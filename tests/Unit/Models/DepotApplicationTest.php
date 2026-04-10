<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DepotApplication;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests unitaires pour le modèle DepotApplication
 */
class DepotApplicationTest extends TestCase
{
    #[Test]
    public function it_has_correct_table_name(): void
    {
        $model = new DepotApplication();
        $this->assertEquals('depot_applications', $model->getTable());
    }

    #[Test]
    public function it_has_correct_fillable_attributes(): void
    {
        $model = new DepotApplication();
        $fillable = $model->getFillable();
        
        $this->assertContains('depot_id', $fillable);
        $this->assertContains('app_id', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('version', $fillable);
        $this->assertContains('category', $fillable);
        $this->assertContains('compatibility', $fillable);
        $this->assertContains('branch', $fillable);
        $this->assertContains('xml_url', $fillable);
        $this->assertContains('xml_sha', $fillable);
        $this->assertContains('log_url', $fillable);
        $this->assertContains('icon_url', $fillable);
        $this->assertContains('last_checked_at', $fillable);
    }

    #[Test]
    public function it_casts_depot_id_to_integer(): void
    {
        $model = new DepotApplication();
        $casts = $model->getCasts();
        
        $this->assertArrayHasKey('depot_id', $casts);
        $this->assertEquals('integer', $casts['depot_id']);
    }

    #[Test]
    public function it_casts_last_checked_at_to_datetime(): void
    {
        $model = new DepotApplication();
        $casts = $model->getCasts();
        
        $this->assertArrayHasKey('last_checked_at', $casts);
        $this->assertEquals('datetime', $casts['last_checked_at']);
    }

    #[Test]
    public function it_implements_wireable_interface(): void
    {
        $this->assertTrue(
            in_array('Livewire\Wireable', class_implements(DepotApplication::class))
        );
    }

    #[Test]
    public function it_has_depot_relationship(): void
    {
        $model = new DepotApplication();
        $this->assertTrue(method_exists($model, 'depot'));
    }

    #[Test]
    public function it_has_by_category_scope(): void
    {
        $this->assertTrue(method_exists(DepotApplication::class, 'scopeByCategory'));
    }

    #[Test]
    public function it_has_by_branch_scope(): void
    {
        $this->assertTrue(method_exists(DepotApplication::class, 'scopeByBranch'));
    }

    #[Test]
    public function it_has_search_scope(): void
    {
        $this->assertTrue(method_exists(DepotApplication::class, 'scopeSearch'));
    }

    #[Test]
    public function it_has_is_installed_locally_method(): void
    {
        $this->assertTrue(method_exists(DepotApplication::class, 'isInstalledLocally'));
    }

    #[Test]
    public function it_has_to_livewire_method(): void
    {
        $model = new DepotApplication();
        $model->id = 123;
        
        $livewireData = $model->toLivewire();
        
        $this->assertIsArray($livewireData);
        $this->assertArrayHasKey('id', $livewireData);
        $this->assertEquals(123, $livewireData['id']);
    }
}
