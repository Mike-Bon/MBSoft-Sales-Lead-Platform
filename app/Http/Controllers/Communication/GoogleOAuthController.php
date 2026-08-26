<?php

namespace App\Http\Controllers\Communication;

use App\Enums\EmailAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use Google\Client as GoogleClient;
use Google\Service\Oauth2;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 6/7: connects one user's own Gmail account via OAuth2. Never
 * handles a Gmail password — only Google's own hosted consent screen
 * does, and this controller only ever sees the authorization code
 * Google redirects back with, exchanged server-side for tokens that are
 * immediately stored encrypted (EmailAccount::casts) and never returned
 * to the browser.
 */
class GoogleOAuthController extends Controller
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function redirect(Request $request): RedirectResponse
    {
        $client = $this->client();
        $client->setState((string) $request->user()->id);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        // The OAuth `state` parameter must match the user who initiated
        // the flow — otherwise a stolen/replayed callback URL could
        // attach Google's tokens to a different, currently-logged-in
        // account.
        if ($request->query('state') !== (string) $request->user()->id) {
            return redirect()->route('communications.email-account.edit')
                ->with('error', 'The connection request could not be verified. Please try again.');
        }

        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));

        if (isset($token['error'])) {
            return redirect()->route('communications.email-account.edit')
                ->with('error', 'Google declined the connection request.');
        }

        $client->setAccessToken($token);
        $email = $this->fetchEmailAddress($client);

        $account = EmailAccount::firstOrNew(['user_id' => $request->user()->id]);
        $account->email_address = $email;
        $account->access_token = $token['access_token'];
        $account->refresh_token = $token['refresh_token'] ?? $account->refresh_token;
        $account->token_expires_at = now()->addSeconds($token['expires_in'] ?? 3600);
        $account->scopes = implode(' ', self::SCOPES);
        $account->status = EmailAccountStatus::Connected;
        $account->last_error = null;
        $account->connected_at = now();
        $account->user_id = $request->user()->id;
        $account->save();

        return redirect()->route('communications.email-account.edit')->with('status', 'Gmail account connected.');
    }

    public function edit(Request $request): View
    {
        return view('communications.email-account.edit', [
            'account' => $request->user()->emailAccount,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = $request->user()->emailAccount;

        if ($account) {
            // Never keep a disconnected account's tokens around —
            // once the user disconnects, the credentials are revoked
            // from this application's perspective.
            $account->delete();
        }

        return redirect()->route('communications.email-account.edit')->with('status', 'Gmail account disconnected.');
    }

    private function client(): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function fetchEmailAddress(GoogleClient $client): string
    {
        $oauth2 = new Oauth2($client);

        return $oauth2->userinfo->get()->getEmail();
    }
}
