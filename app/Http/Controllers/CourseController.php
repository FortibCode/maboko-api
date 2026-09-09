<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Liste des courses']);
    }

    public function show($id)
    {
        return response()->json(['message' => "Détails de la course $id"]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => 'Course créée avec succès']);
    }
}
