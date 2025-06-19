<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\LandingPageContent;
use Illuminate\Http\Request;

class LandingPageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'page_title' => 'required|string|max:255',
            'page_slug' => 'required|string|max:255|unique:landing_pages',
            'isPublished' => 'required|boolean',
            'author' => 'required|string|max:255',
            'date' => 'required|date',
            'serial' => 'required|integer',
            'layout_type' => 'required|string|max:255',
            'layout_content' => 'required|array'
        ]);

        $content = LandingPageContent::create([
            'serial' => $validated['serial'],
            'layout_type' => $validated['layout_type'],
            'layout_content' => $validated['layout_content']
        ]);

        $landingPage = LandingPage::create([
            'page_title' => $validated['page_title'],
            'page_slug' => $validated['page_slug'],
            'content_id' => $content->id,
            'isPublished' => $validated['isPublished'],
            'author' => $validated['author'],
            'date' => $validated['date']
        ]);

        return response()->json($landingPage, 201);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(LandingPage $landingPage)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LandingPage $landingPage)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LandingPage $landingPage)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LandingPage $landingPage)
    {
        //
    }
}
