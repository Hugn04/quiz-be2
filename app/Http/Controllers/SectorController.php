<?php

namespace App\Http\Controllers;

use App\Models\Sector;

class SectorController extends Controller
{
    /** GET /sectors — danh sách ngành (công khai). */
    public function index()
    {
        return response()->json(Sector::get());
    }
}
