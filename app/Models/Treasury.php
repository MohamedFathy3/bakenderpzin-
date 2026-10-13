<?php

namespace App\Models;

class Treasury extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_main' => 'boolean',
        'currencies' => 'array', // تحويل JSON إلى array تلقائياً
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function setCurrenciesAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['currencies'] = json_encode($value);
        } else {
            $this->attributes['currencies'] = $value;
        }
    }
    // دالة لجلب رصيد عملة معينة
    public function getBalanceByCurrency($currencyId, ?string $currencyCode = null)
    {
        $currencies = $this->currencies ?? [];

        foreach ($currencies as $currency) {
            if (($currency['currency_id'] ?? null) == $currencyId) {
                return (float)($currency['balance'] ?? 0);
            }
        }

        if ($currencyCode !== null && strcasecmp((string) $this->currency, $currencyCode) === 0) {
            return (float) $this->balance;
        }

        return 0;
    }
   public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'treasury_id');
    }
      public function transactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class);
    }
 public function getBalanceAttribute($value)
    {
        return (float) $value;
    }
    // دالة لتحديث رصيد عملة معينة
    public function updateBalance($currencyId, $amount, ?string $currencyCode = null)
    {
        $currencies = $this->currencies ?? [];
        $found = false;

        foreach ($currencies as &$currency) {
            if (($currency['currency_id'] ?? null) == $currencyId) {
                $currency['balance'] = (float)($currency['balance'] ?? 0) + $amount;
                $found = true;
                break;
            }
        }

        if (!$found && $currencyCode !== null && strcasecmp((string) $this->currency, $currencyCode) === 0) {
            $this->balance = (float) $this->balance + $amount;
            $this->save();

            return $this;
        }

        if (!$found) {
            $currencies[] = [
                'currency_id' => $currencyId,
                'balance' => $amount,
                'is_main' => empty($currencies),
            ];
        }

        $this->currencies = $currencies;
        $this->save();

        return $this;
    }

    // دالة لجلب كل الأرصدة
    public function getBalancesAttribute()
    {
        return $this->currencies ?? [];
    }
}
