<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryAdminWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    public function test_index_has_icon_column(): void
    {
        $this->actingAs($this->admin())->get('/admin/categories')
            ->assertOk()->assertSee(__('الأيقونة'));
    }

    public function test_create_category_with_icon_upload(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'فئة بأيقونة',
            'sort_order' => 0,
            'is_active' => 1,
            'icon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect();

        $category = Category::where('name', 'فئة بأيقونة')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->image);
        Storage::disk('public')->assertExists($category->image);
        $this->assertNotNull($category->iconUrl());
    }

    public function test_update_replaces_icon(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create(['image' => 'categories/old.png']);
        Storage::disk('public')->put('categories/old.png', 'x');

        $this->actingAs($this->admin())->put('/admin/categories/'.$category->uuid, [
            'name' => $category->name,
            'sort_order' => 0,
            'is_active' => 1,
            'icon' => UploadedFile::fake()->image('new.png', 64, 64),
        ])->assertRedirect();

        $fresh = $category->fresh();
        $this->assertNotSame('categories/old.png', $fresh->image);
        Storage::disk('public')->assertMissing('categories/old.png'); // حُذف القديم
        Storage::disk('public')->assertExists($fresh->image);
    }
}
