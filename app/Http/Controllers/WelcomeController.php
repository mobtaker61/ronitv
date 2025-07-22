<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WelcomeController extends Controller
{
    public function index()
    {
        $disk = Storage::disk('videos');
        $directories = $disk->directories();

        return view('welcome', compact('directories'));
    }
}
