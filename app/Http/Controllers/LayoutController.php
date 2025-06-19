<?php

namespace App\Http\Controllers;

use App\Models\Layout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class LayoutController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
    }
    public function layouts()
    {
        $data = Layout::select('page')->distinct()->get();
        return response()->json(['data' => $data]);
    }

    public function position($layout)
    {
        $data = Layout::where('page', $layout)->select('position')->distinct()->get();
        return response()->json(['data' => $data]);
    }

    public function pageLayout($page){
        $data = Layout::where('page',$page)->get();
        return response()->json($data);
    }

    public function positionLayout($layout, $position)
    {
        $query = Layout::where('page', $layout)->where('position', $position);
        $timestamp = request()->input('d');
        if($timestamp){
            $date = Carbon::createFromTimestamp($timestamp / 1000);
            $date->setTimezone('America/Chicago');
            $today = $date->format('Y-m-d H:i:s');
            $query->where(function ($query) use ($today) {
                $query->where(function ($query) use ($today) {
                    $query->where('start_date', '<=', $today)
                          ->orWhereNull('start_date');
                })
                ->where(function ($query) use ($today) {
                    $query->where('end_date', '>=', $today)
                          ->orWhereNull('end_date');
                });
            });
        }
        $data = $query->get();
        
        // $data = Layout::where('page', $layout)->where('position', $position)->get();
        return response()->json(['data' => $data]);
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $data = $user->capabilities;
        $isAdmin= false;
        foreach ($data as $key => $value) {
            if ($key == 'administrator') {
                $isAdmin = true;
            }
        }
        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'You are not allowed']);
        }
        $validator = Validator::make($request->all(), [
            'page' => ['required', 'string'],
            'position' => ['required', 'string'],
            'serial' => ['required', 'numeric'],
            'status'=>['required'],
            'link' => ['string'],
            'url' => ['nullable','string'],
            'visibility' => ['required', 'string'],
            'start_date'=>['nullable','date'],  
            'end_date'=>['nullable','date','after_or_equal:start_date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        if ($request->hasFile('url')) {
            try {
                $thumbnail = $request->file('url');
                $thumbnailPath = $thumbnail->store('layouts', 'public');
                $validatedData['url'] = $thumbnailPath;
            } catch (\Exception $e) {
                return response()->json(['error' => 'File upload failed'], 500);
            }
        }

        $post = Layout::create($validatedData);

        return response()->json(['status' => 'success', 'data' => $post, 'message' => 'Layout stored successfully']);
    }


    /**
     * Display the specified resource.
     */
    public function show(Layout $layout)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Layout $layout)
    {
        //
    }


    public function uploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'thumbnail' => ['required', 'mimes:jpeg,png,jpg,gif,webp,avif,mp4,pdf', 'max:4048'],
            'old_url' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if ($request->hasFile('thumbnail')) {
            try {
                if ($request->filled('old_url')) {
                    Storage::disk('public')->delete($request->input('old_url'));
                }

                $thumbnail = $request->file('thumbnail');
                $thumbnailPath = $thumbnail->store('layouts', 'public');

                return response()->json(['status' => 'success', 'url' => $thumbnailPath], 201);
            } catch (\Exception $e) {
                return response()->json(['error' => 'File upload failed'], 500);
            }
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'page' => ['required', 'string'],
            'position' => ['required', 'string'],
            'serial' => ['required', 'numeric'],
            'status'=>['required'],
            'link' => ['string'],
            'url' => ['string','nullable'],
            'visibility' => ['string'],
            'end_date'=>['nullable'],
            'start_date'=>['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        $post = Layout::find($id);
        if (!$post) {
            return response()->json(['error' => 'Layout not found'], 404);
        }

        $post->update($validatedData);

        return response()->json(['status' => 'success', 'data' => $post, 'message' => 'Layout updated successfully']);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $post = Layout::find($id);
        if (!$post) {
            return response()->json(['error' => 'Layout not found'], 404);
        }

        // Delete the file if exists
        if ($post->url) {
            Storage::disk('public')->delete($post->url);
        }

        $post->delete();

        return response()->json(['status' => 'success', 'message' => 'Layout deleted successfully']);
    }
}
