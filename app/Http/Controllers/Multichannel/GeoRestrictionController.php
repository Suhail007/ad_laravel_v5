<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GeoRestriction;
use App\Models\GeoRestrictionLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class GeoRestrictionController extends Controller
{
    public function searchToApply(Request $request){
        $searchTerm = $request->query('s',null);
        $searchType = $request->query('type',null);
        $data = null;
        if(!empty($searchTerm) && $searchType == 'category'){
            $data = Category::with([
                'categorymeta' => function ($query) {
                    $query->where('meta_key', 'visibility');
                },
                'taxonomy' => function ($query) {
                    $query->select('term_id', 'taxonomy');
                }
            ])
                ->whereNested(function ($query) use ($searchTerm) {
                    $query->where('name', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('slug', 'LIKE', '%' . $searchTerm . '%');
                })
                ->whereHas('taxonomy', function ($query) {
                    $query->where('taxonomy', 'product_cat');
                })
                ->take(10)
                ->get();
        } else if(!empty($searchTerm) && $searchType == 'brand'){
            $data = Category::with([
                'categorymeta' => function ($query) {
                    $query->where('meta_key', 'visibility');
                },
                'taxonomy' => function ($query) {
                    $query->select('term_id', 'taxonomy');
                }
                ])
                ->whereHas('taxonomy', function ($query) {
                    $query->where('taxonomy', 'product_brand');
                })
                ->whereNested(function ($query) use ($searchTerm) {
                    $query->where('name', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('slug', 'LIKE', '%' . $searchTerm . '%');
                })
                ->take(10)
                ->get();
        } else {
            $data = Product::with(['thumbnail','categories','variations','meta','variations.varients'])
            ->where('post_parent', 0)
            ->where('post_type', 'product')
            ->where(function ($query) use ($searchTerm) {
                $query->where(function ($q) use ($searchTerm) {
                $q->where('post_title', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('post_name', 'LIKE', '%' . $searchTerm . '%');
                })
                ->orWhereHas('meta', function ($q) use ($searchTerm) {
                    $q->where('meta_key', '_sku')
                    ->where('meta_value', 'LIKE', '%' . $searchTerm . '%');
                });
            })
            ->take(10)
            ->get();
            // $data = $data->map(function($item){
            //     $item->categories = $item->categories->map(function($category){
            //         return $category->taxonomies;
            //     });
            //     return $item;
            // }); 

        }
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function getLocationList(Request $request){
        $searchTerm = $request->query('s',null);
        $searchIn = $request->query('type',null);
        $data = null;
        if($searchIn == 'zip'){
            $data = DB::table('location_list')->where('zip','LIKE','%'.$searchTerm.'%')->take(10)->get();
        }elseif($searchIn == 'city'){
            $data = DB::table('location_list')->where('city','LIKE','%'.$searchTerm.'%')->take(10)->get();
        }elseif($searchIn == 'state'){
            $data = DB::table('location_list')->where('state_id','LIKE','%'.$searchTerm.'%')->take(10)->get();
        }
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function toggleStatus($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $restriction = GeoRestriction::findOrFail($id);
            $oldStatus = $restriction->is_active;

            DB::beginTransaction();

            $restriction->update(['is_active' => !$oldStatus]);

            // Log the status change
            GeoRestrictionLog::create([
                'geo_restriction_id' => $restriction->id,
                'user_id' => $user->ID,
                'action' => $restriction->is_active ? 'enabled' : 'disabled',
                'changes' => [
                    'old_status' => $oldStatus,
                    'new_status' => $restriction->is_active
                ]
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Geo restriction rule status updated successfully',
                'data' => $restriction
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error updating geo restriction status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function preview(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $validator = Validator::make($request->all(), [
                'rule_type' => 'required|in:allow,disallow',
                'scope' => 'required|in:product,category,brand',
                'target_entities' => 'required|array',
                'target_entities.*' => 'required|integer',
                'locations' => 'required|array',
                'locations.*.type' => 'required|in:city,state,zip',
                'locations.*.value' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get affected entities based on scope
            $affectedEntities = $this->getAffectedEntities($request->scope, $request->target_entities);

            return response()->json([
                'status' => true,
                'data' => [
                    'affected_entities' => $affectedEntities,
                    'locations' => $request->locations
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error previewing geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function getAffectedEntities($scope, $targetEntities)
    {
        switch ($scope) {
            case 'product':
                return DB::table('wp_posts')
                    ->whereIn('ID', $targetEntities)
                    ->where('post_type', 'product')
                    ->select('ID', 'post_title as name', 'post_name as slug')
                    ->get();

            case 'category':
                return DB::table('wp_terms')
                    ->join('wp_term_taxonomy', 'wp_terms.term_id', '=', 'wp_term_taxonomy.term_id')
                    ->whereIn('wp_terms.term_id', $targetEntities)
                    ->where('wp_term_taxonomy.taxonomy', 'product_cat')
                    ->select('wp_terms.term_id as ID', 'wp_terms.name as name', 'wp_terms.slug as slug')
                    ->get();

            case 'brand':
                return DB::table('wp_terms')
                    ->join('wp_term_taxonomy', 'wp_terms.term_id', '=', 'wp_term_taxonomy.term_id')
                    ->whereIn('wp_terms.term_id', $targetEntities)
                    ->where('wp_term_taxonomy.taxonomy', 'brand')
                    ->select('wp_terms.term_id as ID', 'wp_terms.name as name', 'wp_terms.slug as slug')
                    ->get();
            case 'product_in_detail':
                return Product::whereIn('ID', $targetEntities)->with([
                    'thumbnail',
                    'categories'=>function($query){
                        $query->select('term_id','name','slug');
                    },
                    'categories.categorymeta'=>function($query){
                        $query->select('meta_id','term_id','meta_key','meta_value');
                    },
                    'variations'=>function($query){
                        $query->select('ID','post_parent','post_title','post_name','post_status','post_parent','post_type');
                    },
                    'meta'=>function($query){
                        $query->select('meta_id','post_id','meta_key','meta_value')->whereIn('meta_key',['_price','_stock','_stock_status','_sku','_thumbnail_id','_product_image_gallery','min_quantity','max_quantity','sessions_limit_data','deactivated_max_quantity_var','sessions_limit_data_created_at']);
                    },
                    'variations.varients'
                    // =>function($query){
                    //     $query->select('meta_id','post_id','meta_key','meta_value')
                    //     ->whereIn('meta_key',['_price','_stock','_stock_status','_sku','_thumbnail_id','_product_image_gallery','min_quantity','max_quantity','sessions_limit_data','deactivated_max_quantity_var','sessions_limit_data_created_at']);
                    // }
                    ])->get();
            case 'product_in_detail_single':
                return Product::whereIn('ID', $targetEntities)->with([
                    'thumbnail',
                    'categories'=>function($query){
                        $query->select('term_id','name','slug');
                    },
                    'categories.categorymeta'=>function($query){
                        $query->select('meta_id','term_id','meta_key','meta_value');
                    },
                    'variations'=>function($query){
                        $query->select('ID','post_parent','post_title','post_name','post_status','post_parent','post_type');
                    },
                    'meta'=>function($query){
                        $query->select('meta_id','post_id','meta_key','meta_value')->whereIn('meta_key',['_price','_stock','_stock_status','_sku','_thumbnail_id','_product_image_gallery','min_quantity','max_quantity','sessions_limit_data','deactivated_max_quantity_var','sessions_limit_data_created_at']);
                    },
                    'variations.varients'=>function($query){
                        $query->select('meta_id','post_id','meta_key','meta_value')
                        ->whereIn('meta_key',['_price','_stock','_stock_status','_sku','_thumbnail_id','_product_image_gallery','min_quantity','max_quantity','sessions_limit_data','deactivated_max_quantity_var','sessions_limit_data_created_at'])
                        ->orWhere('meta_key', 'like', 'attribute_%');
                    }
                    ])->get();
            case 'category_in_detail':
                return Category::whereIn('term_id', $targetEntities)->with(['categorymeta','taxonomy'])->get();
            case 'brand_in_detail':
                return Category::whereIn('term_id', $targetEntities)->with(['categorymeta','taxonomy'])->get();

            default:
                return [];
        }
    }
    public function index(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $query = GeoRestriction::query();

            // Apply filters
            if ($request->has('rule_type')) {
                $query->where('rule_type', $request->rule_type);
            }

            if ($request->has('scope')) {
                $query->where('scope', $request->scope);
            }

            if ($request->has('is_active')) {
                $st = ($request->is_active === 'true') ? 1 : 0;
                $query->where('is_active', $st);
            }

            // Filter by locations
            if ($request->has('location')) {
                $location = $request->location;
                $query->where(function($q) use ($location) {
                    $q->whereJsonContains('locations', [
                        'type' => $location['type'] ?? null,
                        'value' => $location['value'] ?? null
                    ]);
                });
            }

            // Filter by location type
            if ($request->has('location_type')) {
                $query->whereJsonContains('locations', [
                    'type' => $request->location_type
                ]);
            }

            // Filter by location value
            if ($request->has('location_value')) {
                $query->whereJsonContains('locations', [
                    'value' => $request->location_value
                ]);
            }

            // Apply search
            if ($request->has('search')) {
                $searchTerm = $request->search;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Apply sorting
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            $restrictions = $query->paginate($request->input('per_page', 15));

            // Enhance the data with entity details
            $data = $restrictions->items();
            foreach ($data as $restriction) {
                if($restriction->scope == 'product'){
                    $restriction->target_entities_details = $this->getAffectedEntities('product_in_detail', $restriction->target_entities);
                }elseif($restriction->scope == 'category'){
                    $restriction->target_entities_details = $this->getAffectedEntities('category_in_detail', $restriction->target_entities);
                }elseif($restriction->scope == 'brand'){
                    $restriction->target_entities_details = $this->getAffectedEntities('brand_in_detail', $restriction->target_entities);
                }
            }

            return response()->json([
                'status' => true,
                'data' => $restrictions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching geo restrictions: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function checkForDuplicateRules($request, $excludeId = null)
    {
        $query = GeoRestriction::where('scope', $request->scope)
            ->where('is_active', true);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Check if any of the target entities are already in use
        $existingRules = $query->get();
        
        foreach ($existingRules as $rule) {
            $intersection = array_intersect($rule->target_entities, $request->target_entities);
            if (!empty($intersection)) {
                return true;
            }
        }

        return false;
    }

    public function store(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'rule_type' => 'required|in:allow,disallow',
                'scope' => 'required|in:product,category,brand',
                'target_entities' => 'required|array',
                'target_entities.*' => 'required|integer',
                'locations' => 'required|array',
                'locations.*.type' => 'required|in:city,state,zip',
                'locations.*.value' => 'required|string',
                'description' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], );
            }

            // Check for duplicate rules
            if ($this->checkForDuplicateRules($request)) {
                return response()->json([
                    'status' => false,
                    'message' => 'One or more target entities are already associated with another geo restriction rule'
                ], );
            }

            DB::beginTransaction();

            $restriction = GeoRestriction::create($request->all());

            // Log the creation
            GeoRestrictionLog::create([
                'geo_restriction_id' => $restriction->id,
                'user_id' => $user->ID,
                'action' => 'created',
                'changes' => $request->all()
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Geo restriction rule created successfully',
                'data' => $restriction
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error creating geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $restriction = GeoRestriction::with('logs')->findOrFail($id);
            if($restriction->scope == 'product'){
                $restriction->target_entities_details = $this->getAffectedEntities('product_in_detail', $restriction->target_entities);
            }elseif($restriction->scope == 'category'){
                $restriction->target_entities_details = $this->getAffectedEntities('category_in_detail', $restriction->target_entities);
            }elseif($restriction->scope == 'brand'){
                $restriction->target_entities_details = $this->getAffectedEntities('brand_in_detail', $restriction->target_entities);
            }
            return response()->json([
                'status' => true,
                'data' => $restriction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'string|max:255',
                'rule_type' => 'in:allow,disallow',
                'scope' => 'in:product,category,brand',
                'target_entities' => 'array',
                'target_entities.*' => 'integer',
                'locations' => 'array',
                'locations.*.type' => 'in:city,state,zip',
                'locations.*.value' => 'string',
                'description' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], );
            }

            $restriction = GeoRestriction::findOrFail($id);
            $oldData = $restriction->toArray();

            // Check for duplicate rules if target_entities or scope is being updated
            if (($request->has('target_entities') || $request->has('scope')) && 
                $this->checkForDuplicateRules($request, $id)) {
                return response()->json([
                    'status' => false,
                    'message' => 'One or more target entities are already associated with another geo restriction rule'
                ], );
            }

            DB::beginTransaction();

            $restriction->update($request->all());

            // Log the changes
            GeoRestrictionLog::create([
                'geo_restriction_id' => $restriction->id,
                'user_id' => $user->ID,
                'action' => 'updated',
                'changes' => [
                    'old' => $oldData,
                    'new' => $request->all()
                ]
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Geo restriction rule updated successfully',
                'data' => $restriction
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error updating geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            
            if (!isset($capabilities['administrator'])) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access'], 403);
            }

            $restriction = GeoRestriction::findOrFail($id);
            $oldData = $restriction->toArray();

            DB::beginTransaction();

            $restriction->delete();

            // Log the deletion
            GeoRestrictionLog::create([
                'geo_restriction_id' => $id,
                'user_id' => $user->ID,
                'action' => 'deleted',
                'changes' => ['deleted_data' => $oldData]
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Geo restriction rule deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error deleting geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function duplicate($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $originalRestriction = GeoRestriction::findOrFail($id);
            
            DB::beginTransaction();

            // Create a new restriction with the same data but modified name and inactive status
            $newRestriction = $originalRestriction->replicate();
            $newRestriction->name = $originalRestriction->name . ' - (COPY)';
            $newRestriction->is_active = false;
            $newRestriction->save();

            // Log the duplication
            GeoRestrictionLog::create([
                'geo_restriction_id' => $newRestriction->id,
                'user_id' => $user->ID,
                'action' => 'duplicated',
                'changes' => [
                    'original_id' => $originalRestriction->id,
                    'original_name' => $originalRestriction->name
                ]
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Geo restriction rule duplicated successfully',
                'data' => $newRestriction
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error duplicating geo restriction: ' . $e->getMessage()
            ], 500);
        }
    }

}
