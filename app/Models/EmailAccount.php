<?php

namespace App\Models;

use App\Enums\EmailAccountStatus;
use Database\Factories\EmailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single user's connected Gmail account (STEP 7) — one per user
 * (enforced by a unique index on user_id), never a shared organisation
 * mailbox. access_token/refresh_token are wrapped in Laravel's
 * `encrypted` cast, which transparently encrypts with APP_KEY before
 * storage and decrypts on read; they are never exposed to the browser
 * (excluded from any array/JSON serialization the frontend could reach —
 * see CommunicationController, which never returns this model raw) and
 * never written to logs (see GmailEmailProvider, which logs only the
 * account id/email address on error, never the tokens).
 */
class EmailAccount extends Model
{
    /** @use HasFactory<EmailAccountFactory> */
    use HasFactory;

    /**
     * Deliberately excludes every field here from mass assignment —
     * user_id, tokens, and status are always set explicitly by
     * GoogleOAuthController/CommunicationService, never from arbitrary
     * request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $attributes = [
        'status' => EmailAccountStatus::Connected->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailAccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
    }
}
