<?php

namespace App\Models;

use App\Enums\WhatsAppNumberStatus;
use Database\Factories\WhatsAppBusinessNumberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business-owned WhatsApp Business Platform number (STEP 11), managed
 * organisation-wide or scoped to one team. Holds no credentials — see
 * the migration docblock and docs/COMMUNICATIONS.md for why the access
 * token/app secret live in config/services.php instead.
 */
class WhatsAppBusinessNumber extends Model
{
    /** @use HasFactory<WhatsAppBusinessNumberFactory> */
    use HasFactory;

    /**
     * Eloquent's naive snake_case conversion of "WhatsAppBusinessNumber"
     * doesn't recognise "WhatsApp" as one word, so the table name must
     * be given explicitly.
     */
    protected $table = 'whatsapp_business_numbers';

    /**
     * Deliberately excludes `team_id` and `created_by`: which team (if
     * any) a business number belongs to, and who registered it, are
     * Manager-only decisions made explicitly by
     * WhatsAppNumberController/CommunicationService, never taken from
     * arbitrary request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'phone_number',
        'phone_number_id',
        'waba_id',
        'status',
    ];

    protected $attributes = [
        'status' => WhatsAppNumberStatus::Connected->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WhatsAppNumberStatus::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class, 'whatsapp_number_id');
    }
}
