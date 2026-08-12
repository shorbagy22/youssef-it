<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Authenticated admin UI for managing Departments - the source of truth
 * for the public dashboard's department list, /chat/{slug} pages, and the
 * department dropdown on /admin/sources.
 */
final class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('admin.departments.index', [
            'departments' => Department::query()->withCount('sources')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.departments.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('departments', 'slug')],
        ]);

        Department::query()->create($validated);

        return redirect()
            ->route('admin.departments.index')
            ->with('status', 'Department created.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        if ($department->sources()->exists()) {
            return redirect()
                ->route('admin.departments.index')
                ->with('error', "Can't delete \"{$department->name}\" - it still has sources assigned to it.");
        }

        $department->delete();

        return redirect()
            ->route('admin.departments.index')
            ->with('status', 'Department deleted.');
    }
}
