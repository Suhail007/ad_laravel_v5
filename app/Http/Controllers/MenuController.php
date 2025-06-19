<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandMenu;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class MenuController extends Controller
{
    public function publiclist()
    {
        $categories = Category::getCategoriesWithChildren();
        $brand = Brand::get();
        // $brandMenus = BrandMenu::all();
        $brandMenus = BrandMenu::orderBy('order', 'asc')->get();
        return response()->json(['status' => true, 'category' => $categories, 'brand' => $brand, 'menu' => $brandMenus , ]);
    }
    public function flavorList(){
        $flavors = DB::table('wp_postmeta')->where('meta_key','attribute_flavor')->distinct()->select('meta_value')->paginate(30);
        return response()->json($flavors);
    }
    public function index()
    {
        $brandMenus = BrandMenu::all();
        return response()->json(['status' => true, 'data' => $brandMenus]);
    }

    public function store(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
        }
        $validatedData = $request->validate([
            'term_id' => 'nullable',
            'categoryName' => 'nullable|string|max:255',
            'categorySlug' => 'nullable|string|max:255',
            'categoryVisbility' => 'nullable|string|max:255',
            'order' => 'nullable',
            'menupos' => 'nullable',
            'menuImageUrl' => 'nullable|string'
        ]);

        $brandMenu = BrandMenu::create($validatedData);
        return response()->json(['status' => true, 'message' => 'Brand menu created successfully', 'data' => $brandMenu], 201);
    }

    public function show($id)
    {
        $brandMenu = BrandMenu::find($id);

        if (!$brandMenu) {
            return response()->json(['status' => false, 'message' => 'Brand menu not found'], 404);
        }

        return response()->json($brandMenu);
    }

    public function update(Request $request, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
        }
        $validatedData = $request->validate([
            'term_id' => 'nullable',
            'categoryName' => 'nullable|string|max:255',
            'categorySlug' => 'nullable|string|max:255',
            'categoryVisbility' => 'nullable|string|max:255',
            'order' => 'nullable',
            'menupos' => 'nullable',
            'menuImageUrl' => 'nullable|string'
        ]);

        $brandMenu = BrandMenu::find($id);

        if (!$brandMenu) {
            return response()->json(['status' => false, 'message' => 'Brand menu not found'], 404);
        }

        $brandMenu->update($validatedData);
        return response()->json(['status' => true, 'message' => 'Brand menu updated successfully', 'data' => $brandMenu]);
    }

    public function destroy($id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
        }
        $brandMenu = BrandMenu::find($id);

        if (!$brandMenu) {
            return response()->json(['message' => 'Brand menu not found'], 404);
        }

        $brandMenu->delete();
        return response()->json(['status' => true, 'message' => 'Brand menu deleted successfully']);
    }

    public function fetchAndSaveBrands(Request $request, string $value)
    {
        if ($value == "e68b2f19a45e9070") {
            // bypass wordpress request on brand update
        } else {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
            }
        }

        $categoryIds = BrandMenu::pluck('term_id')->toArray();
        DB::beginTransaction();

        try {
            Brand::whereIn('term_id', function ($query) use ($categoryIds) {
                $query->select('term_id')
                    ->from('wp_term_taxonomy')
                    ->whereIn('term_id', $categoryIds);
            })->delete();

            foreach ($categoryIds as $categoryId) {
                $getSlug = Category::with(['categorymeta' => function ($query) {
                    $query->select('term_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['visibility', 'thumbnail_id']);
                }])->where('term_id', $categoryId)->first();
                $slug = $getSlug->slug;
                $Name = $getSlug->name;
                $term_id = $getSlug->term_id;
                // dd($slug);

                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->whereIn('meta_key', ['visibility', 'thumbnail_id','brand_recommend_product_list','brand_priority_field','brand_image_id','banner_id']);
                                },
                                'taxonomies' => function ($query) {
                                    $query->select('term_id', 'taxonomy');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->where('slug', $slug)->where('taxonomy', 'product_cat');
                    })
                    ->orderBy('post_date', 'desc')
                    ->get();
                $brandData = $products->flatMap(function ($product) {
                    return $product->categories->filter(function ($category) {
                        return $category->taxonomies->taxonomy === 'product_brand';
                    })->map(function ($category) {

                        $thumbnailIdMeta = $category->categorymeta->firstWhere('meta_key', 'thumbnail_id')??null;
                        $thumbnailId = $thumbnailIdMeta ? $thumbnailIdMeta->meta_value : null;
                        $thumbnailUrl = $this->getbrandUrl($thumbnailId);
                        
                        $bannerMeta = $category->categorymeta->firstWhere('meta_key', 'banner_id')??null;
                        $bannerID = $bannerMeta ? $bannerMeta->meta_value : null;
                        $bannerMetaURL = $this->getbrandUrl($bannerID);
                        
                        $imageMeta = $category->categorymeta->firstWhere('meta_key', 'brand_image_id')??null;
                        $imageID = $imageMeta ? $imageMeta->meta_value : null;
                        $imageMetaUrl = $this->getbrandUrl($imageID);
                        
                        $brandMeta = $category->categorymeta->firstWhere('meta_key', 'brand_recommend_product_list')??null;

                        $priority = $category->categorymeta->firstWhere('meta_key', 'brand_priority_field')??null;
                        $priorityVal = $priority->meta_value??0;
                    
                        return [
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'thumbnail_url' => $thumbnailUrl,
                            'image' => $imageMetaUrl,
                            'banner' => $bannerMetaURL,
                            'meta' => isset($brandMeta->meta_value) ? json_decode($brandMeta->meta_value) : null,
                            'priority' => $priorityVal
                        ];
                    });
                });
                if ($brandData) {
                    foreach ($brandData as $brandData) {
                        Brand::updateOrCreate(
                            [
                                'term_id' => $term_id,
                                'categoryName' => $Name ?? null,
                                'categorySlug' => $slug ?? null,
                                'categoryVisbility' => 'public',
                                'brandName' => $brandData['name'] ?? null,
                                'brandUrl' => $brandData['slug'] ?? null,
                                'brandImageurl' => $brandData['thumbnail_url'] ?? null,
                                'image' => $brandData['image'] ?? null,
                                'banner' => $brandData['banner'] ?? null,
                                'meta' => json_encode($brandData['meta']) ?? null,
                                'visibility' =>'public',
                                'priority'=>(int) $brandData['priority']??0,
                                'status' => true,
                            ]
                        );
                    }
                }
            }
            DB::commit();

            return response()->json(['status' => true, 'message' => 'Brands updated successfully.']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'error' => 'Failed to update brands.', 'message' => $e->getMessage() .' '. $e->getLine()], 500);
        }
    }

    private function getbrandUrl($thumbnailId)
    {
        try {
            if($thumbnailId !=null){
                $url = Product::where('ID', $thumbnailId)->value('guid');
                return $url;
            } 
            return null;
        } catch (\Throwable $th) {
            return null;
        }
    }
}
