<?php

namespace App\Services;

use App\Models\GeoRestriction;
use Illuminate\Support\Facades\DB;

class GeoRestrictionService
{
    /**
     * Check if a product is restricted for a given location
     */
    public function isProductRestricted($productId, $location)
    {
        // Check product-specific restrictions
        $productRestrictions = GeoRestriction::active()
            ->where('scope', 'product')
            ->whereJsonContains('target_entities', $productId)
            ->get();

        foreach ($productRestrictions as $restriction) {
            if ($this->isLocationRestrictedByRule($restriction, $location)) {
                return true;
            }
        }

        // Check category restrictions
        $categoryIds = $this->getProductCategories($productId);
        if(count($categoryIds) > 0) {
            $categoryRestrictions = GeoRestriction::active()
                ->where('scope', 'category')
                ->where(function($query) use ($categoryIds) {
                    foreach ($categoryIds as $categoryId) {
                        $query->orWhereJsonContains('target_entities', $categoryId);
                    }
                })
                ->get();

            foreach ($categoryRestrictions as $restriction) {
                if ($this->isLocationRestrictedByRule($restriction, $location)) {
                        return true;
                }
            }
        }

        // Check brand restrictions
        $brandIds = $this->getProductBrands($productId);
        if(count($brandIds) > 0) {
            $brandRestrictions = GeoRestriction::active()
                ->where('scope', 'brand')
                ->where(function($query) use ($brandIds) {
                    foreach ($brandIds as $brandId) {
                        $query->orWhereJsonContains('target_entities', $brandId);
                    }
                })
                ->get();

            foreach ($brandRestrictions as $restriction) {
                if ($this->isLocationRestrictedByRule($restriction, $location)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a location is restricted by a specific rule
     */
    protected function isLocationRestrictedByRule($restriction, $location)
    {
        $locationMatches = [];
        $locationRules = [];

        foreach ($restriction->locations as $restrictedLocation) {
            if ($this->matchesLocation($location, $restrictedLocation)) {
                $locationMatches[] = $restrictedLocation;
                $locationRules[] = $restrictedLocation['rule_type'] ?? 'restrict';
            }
        }

        // If no locations match:
        if (empty($locationMatches)) {
            // If any location in the rule is 'allow', treat as allow-list: restrict all others
            $hasAllow = collect($restriction->locations)->contains(function($loc) {
                return ($loc['rule_type'] ?? 'restrict') === 'allow';
            });
            if ($hasAllow) {
                return true; // Not in allow-list, so restrict
            }
            // Otherwise, not restricted (no restrict/disallow rule matches)
            return false;
        }

        // If any location has a 'restrict' rule, the product is restricted
        if (in_array('restrict', $locationRules)) {
            return true;
        }

        // If all matching locations have 'allow' rules, the product is not restricted
        if (count(array_unique($locationRules)) === 1 && $locationRules[0] === 'allow') {
            return false;
        }

        // Default to restricted if there's any ambiguity
        return true;
    }
    // protected function isLocationRestrictedByRule($restriction, $location)
    // {
    //     $locationMatches = [];
    //     $locationRules = [];

    //     // First, find all matching locations and their rules
    //     foreach ($restriction->locations as $restrictedLocation) {
    //         if ($this->matchesLocation($location, $restrictedLocation)) {
    //             $locationMatches[] = $restrictedLocation;
    //             // Use the location's rule_type, defaulting to 'restrict' if not specified
    //             $locationRules[] = $restrictedLocation['rule_type'] ?? 'restrict';
    //         }
    //     }

    //     // If no locations match, return false
    //     if (empty($locationMatches)) {
    //         return false;
    //     }

    //     // If any location has a 'restrict' rule, the product is restricted
    //     if (in_array('restrict', $locationRules)) {
    //         return true;
    //     }

    //     // If all matching locations have 'allow' rules, the product is not restricted
    //     if (count(array_unique($locationRules)) === 1 && $locationRules[0] === 'allow') {
    //         return false;
    //     }

    //     // Default to restricted if there's any ambiguity
    //     return true;
    // }

    /**
     * Check if a location matches a restricted location
     */
    protected function matchesLocation($location, $restrictedLocation)
    {
        $matches = false;
        
        if ($restrictedLocation['type'] === 'state') {
            $matches = strtolower($location['state']) === strtolower($restrictedLocation['value']);
        } else if ($restrictedLocation['type'] === 'city') {
            $matches = strtolower($location['city']) === strtolower($restrictedLocation['value']);
        }  else if ($restrictedLocation['type'] === 'zip') {
            $matches = $location['zip'] === $restrictedLocation['value'];
        }

        // if ($restrictedLocation['type'] === 'state') {
        //     return strtolower($location['state']) === strtolower($restrictedLocation['value']);
        // }

        // if ($restrictedLocation['type'] === 'zip') {
        //     return $location['zip'] === $restrictedLocation['value'];
        // }

        return $matches;
    }


    /**
     * Get all restricted products for a given location
     */
    public function getRestrictedProducts($location)
    {
        $restrictedProductIds = collect();

        // Get all active restrictions
        $restrictions = GeoRestriction::active()->get();

        foreach ($restrictions as $restriction) {
            if ($this->isLocationRestrictedByRule($restriction, $location)) {
                switch ($restriction->scope) {
                    case 'product':
                        $restrictedProductIds = $restrictedProductIds->merge($restriction->target_entities);
                        break;

                    case 'category':
                        $categoryIds = $restriction->target_entities;
                        $productIds = $this->getProductsInCategories($categoryIds);
                        $restrictedProductIds = $restrictedProductIds->merge($productIds);
                        break;

                    case 'brand':
                        $brandIds = $restriction->target_entities;
                        $productIds = $this->getProductsInBrands($brandIds);
                        $restrictedProductIds = $restrictedProductIds->merge($productIds);
                        break;
                }
            }
        }

        return $restrictedProductIds->unique()->values()->all();
    }

    /**
     * Get product categories
     */
    protected function getProductCategories($productId)
    {
        return DB::table('wp_term_relationships')
            ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
            ->where('wp_term_relationships.object_id', $productId)
            ->where('wp_term_taxonomy.taxonomy', 'product_cat')
            ->pluck('wp_term_taxonomy.term_id')
            ->toArray();
    }

    /**
     * Get product brands
     */
    protected function getProductBrands($productId)
    {
        return DB::table('wp_term_relationships')
            ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
            ->where('wp_term_relationships.object_id', $productId)
            ->where('wp_term_taxonomy.taxonomy', 'product_brand')
            ->pluck('wp_term_taxonomy.term_id')
            ->toArray();
    }

    /**
     * Get products in categories
     */
    protected function getProductsInCategories($categoryIds)
    {
        return DB::table('wp_term_relationships')
            ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
            ->join('wp_posts', 'wp_term_relationships.object_id', '=', 'wp_posts.ID')
            ->whereIn('wp_term_taxonomy.term_id', $categoryIds)
            ->where('wp_term_taxonomy.taxonomy', 'product_cat')
            ->where('wp_posts.post_type', 'product')
            ->where('wp_posts.post_status', 'publish')
            ->pluck('wp_posts.ID')
            ->toArray();
    }

    /**
     * Get products in brands
     */
    protected function getProductsInBrands($brandIds)
    {
        return DB::table('wp_term_relationships')
            ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
            ->join('wp_posts', 'wp_term_relationships.object_id', '=', 'wp_posts.ID')
            ->whereIn('wp_term_taxonomy.term_id', $brandIds)
            ->where('wp_term_taxonomy.taxonomy', 'product_brand')
            ->where('wp_posts.post_type', 'product')
            ->where('wp_posts.post_status', 'publish')
            ->pluck('wp_posts.ID')
            ->toArray();
    }
} 