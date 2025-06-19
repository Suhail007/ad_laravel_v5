<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Media; // Assuming you have a Media model to keep track of uploaded files

class MediaController extends Controller
{
    public function uploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'thumbnail' => ['required', 'mimes:jpeg,png,jpg,gif,webp,avif,mp4', 'max:4048'],
            'old_url' => ['nullable', 'string'],
            'alt'=>['nullable'],
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
                $thumbnailPath = $thumbnail->store('landing', 'public');
                $thumbnailALt= $request->input('alt');
                $media = Media::create([
                    'url' => $thumbnailPath,
                    'type' => $thumbnail->getClientOriginalExtension(),
                    'alt'=>$thumbnailALt
                    
                ]);

                return response()->json(['status' => 'success', 'url' => $thumbnailPath, 'media_id' => $media->id], 201);
            } catch (\Exception $e) {
                return response()->json(['error' => 'File upload failed'], 500);
            }
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    public function index()
    {
        $mediaFiles = Media::all();
        return response()->json($mediaFiles);
    }

    public function show($id)
    {
        $media = Media::findOrFail($id);
        return response()->json($media);
    }

    public function update(Request $request, $id)
    {
        $media = Media::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'thumbnail' => ['nullable', 'mimes:jpeg,png,jpg,gif,webp,avif,mp4', 'max:4048'],
            'alt'=>['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if ($request->hasFile('thumbnail')) {
            try {
                Storage::disk('public')->delete($media->url);

                $thumbnail = $request->file('thumbnail');
                $thumbnailPath = $thumbnail->store('landing', 'public');

                // Update the file details in the database
                $media->update([
                    'url' => $thumbnailPath,
                    'type' => $thumbnail->getClientOriginalExtension(),
                ]);

                return response()->json(['status' => 'success', 'url' => $thumbnailPath], 200);
            } catch (\Exception $e) {
                return response()->json(['error' => 'File update failed'], 500);
            }
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    public function destroy($id)
    {
        $media = Media::findOrFail($id);

        try {
            // Delete the file
            Storage::disk('public')->delete($media->url);

            // Delete the database record
            $media->delete();

            return response()->json(['status' => 'success'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'File delete failed'], 500);
        }
    }
}
