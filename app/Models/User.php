<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'wp_users'; // Specify the WordPress users table

    protected $primaryKey = 'ID'; // Set the primary key to 'ID'

    protected $guarded = [];
    public $timestamps = false;

    protected $hidden = [
        'user_pass', // Hide the password field
    ];

    public function getAuthPassword()
    {
        return $this->user_pass; // Return the password for authentication
    }

    public function getJWTIdentifier()
    {
        return $this->getKey(); // Ensure this returns the 'ID' field value
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
    public function meta()
    {
        return $this->hasMany(UserMeta::class, 'user_id', 'ID');
    }
    public function getPriceTierAttribute()
    {
        $capabilities = $this->meta()->where('meta_key', 'wp_capabilities')->value('meta_value');

        if ($capabilities) {
            $capabilitiesArray = unserialize($capabilities);
            if (isset($capabilitiesArray['wholesale_customer'])) {
                return 'wholesale_customer_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_2'])) {
                return 'mm_price_2_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_3'])) {
                return 'mm_price_3_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_4'])) {
                return 'mm_price_4_wholesale_price';
            } elseif (isset($capabilitiesArray['mm_price_5'])) {
                return 'mm_price_5_wholesale_price';
            }
        }
        return null;
    }
    public function getAccountAttribute(){
        $account = $this->meta()->where('meta_key', 'mm_field_CID')->value('meta_value');
        return $account ? $account : null;
    }

    public function getMmtaxAttribute(){
        $account = $this->meta()->where('meta_key', 'mm_field_TXC')->value('meta_value');
        return $account ? $account : null;
    }
    public function getCapabilitiesAttribute()
    {
        $capabilities = $this->meta()->where('meta_key', 'wp_capabilities')->value('meta_value');
        return $capabilities ? unserialize($capabilities) : [];
    }
    public function getApprovedAttribute()
    {
        $approved = $this->meta()->where('meta_key', 'ur_user_status')->value('meta_value');
        return $approved;
    }

    public static function generateUniqueUsername($email)
    {
        $baseUsername = strtolower(explode('@', $email)[0]); 
        $username = $baseUsername;
        $counter = 1;

        while (User::where('user_login', $username)->exists()) {
            $username = $baseUsername . $counter; 
            $counter++;
        }

        return $username;
    }
}
