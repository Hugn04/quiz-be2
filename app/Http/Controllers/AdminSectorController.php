<?php

namespace App\Http\Controllers;

use App\Models\AdminLog;
use App\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** /admin/sectors — quản lý ngành học (administrator + manager). */
class AdminSectorController extends Controller
{
    public function index()
    {
        return response()->json(
            Sector::query()->withCount('examples')->orderBy('position')->orderBy('name')->get(['id', 'slug', 'name', 'position'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sectors', 'name')],
        ], ['name.unique' => 'Ngành này đã có.']);
        $sector = Sector::create([
            'name' => trim($data['name']),
            'position' => (int) Sector::max('position') + 1,
        ]);
        AdminLog::record('sector.create', 'sector', $sector->slug, $sector->name);

        return response()->json($sector->loadCount('examples'), 201);
    }

    public function update(Request $request, Sector $sector)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sectors', 'name')->ignore($sector->id)],
        ], ['name.unique' => 'Ngành này đã có.']);
        $old = $sector->name;
        $sector->update(['name' => trim($data['name'])]);
        if ($old !== $sector->name) {
            AdminLog::record('sector.update', 'sector', $sector->slug, $sector->name, ['from' => $old]);
        }

        return response()->json($sector->loadCount('examples'));
    }

    /** Chỉ xóa được ngành chưa có đề (xóa ngành sẽ kéo theo xóa đề — khóa ngoại cascade). */
    public function destroy(Sector $sector)
    {
        $count = $sector->examples()->count();
        if ($count > 0) {
            return response()->json(['message' => "Ngành còn {$count} đề thi, chuyển đề sang ngành khác trước khi xóa."], 422);
        }
        AdminLog::record('sector.delete', 'sector', $sector->slug, $sector->name);
        $sector->delete();

        return response()->noContent();
    }

    /** PUT /admin/sectors/order — Body { ids: [id theo thứ tự mới] } */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:sectors,id'],
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $i => $id) {
                Sector::whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return $this->index();
    }
}
