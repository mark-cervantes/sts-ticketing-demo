<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Category CRUD API controller.
 *
 * Thin: no service layer needed — logic is trivial.
 * Auth enforced by 'auth' middleware on the route group.
 * No Policy needed — categories are open to all authenticated users (SRS §FR-08).
 *
 * @see task 02.03.00 / SRS §FR-08
 */
class CategoryController extends Controller
{
    /**
     * GET /api/categories — list all categories ordered alphabetically by name.
     */
    public function index(): JsonResponse
    {
        $categories = Category::orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json($categories);
    }

    /**
     * POST /api/categories — create a new category with auto-generated slug.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json($category->only(['id', 'name', 'slug']), 201);
    }

    /**
     * DELETE /api/categories/{category} — delete only if no issues reference it.
     *
     * Returns 409 with count message if the category is in use.
     * The FK constraint on issues.category_id is restrictOnDelete(), so the DB
     * would also block this — but we return 409 + count before hitting the DB.
     */
    public function destroy(Category $category): Response|JsonResponse
    {
        $count = $category->issues()->count();

        if ($count > 0) {
            return response()->json(
                ['message' => "Cannot delete: {$count} issues use this category"],
                409
            );
        }

        $category->delete();

        return response()->noContent();
    }
}
