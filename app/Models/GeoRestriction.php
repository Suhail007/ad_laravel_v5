<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GeoRestriction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'rule_type',
        'scope',
        'target_entities',
        'locations',
        'is_active',
        'description',
        'meta'
    ];

    protected $casts = [
        'target_entities' => 'array',
        'locations' => 'array',
        'is_active' => 'boolean',
        'meta' => 'array'
    ];

    public function logs()
    {
        return $this->hasMany(GeoRestrictionLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('rule_type', $type);
    }

    public function scopeByScope($query, $scope)
    {
        return $query->where('scope', $scope);
    }

    public function isLocationRestricted($location)
    {
        if (!$this->is_active) {
            return false;
        }

        foreach ($this->locations as $restrictedLocation) {
            if ($this->matchesLocation($location, $restrictedLocation)) {
                return $this->rule_type === 'disallow';
            }
        }

        return $this->rule_type === 'allow';
    }

    protected function matchesLocation($location, $restrictedLocation)
    {
        if ($restrictedLocation['type'] === 'city') {
            return strtolower($location['city']) === strtolower($restrictedLocation['value']);
        }

        if ($restrictedLocation['type'] === 'state') {
            return strtolower($location['state']) === strtolower($restrictedLocation['value']);
        }

        if ($restrictedLocation['type'] === 'zip') {
            return $location['zip'] === $restrictedLocation['value'];
        }

        return false;
    }
} 