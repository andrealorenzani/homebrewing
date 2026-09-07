<?php

declare(strict_types=1);

namespace Tests\Unit\Recipes;

use App\Db\FakeDb;
use App\Recipes\RecipeRepository;
use Tests\TestCase;

final class RecipeRepositoryTest extends TestCase
{
    private function sampleData(array $overrides = []): array
    {
        return [...[
            'user_id' => 1,
            'name' => 'Session IPA',
            'category' => 'beer',
            'description' => 'A light hoppy beer.',
            'batch_size' => '20.00',
            'batch_size_unit' => 'L',
            'water_quantity' => null,
            'water_unit' => null,
            'sugar_quantity' => null,
            'sugar_unit' => null,
            'sugar_type' => null,
            'yeast_type' => 'US-05',
            'yeast_quantity' => null,
            'yeast_unit' => null,
            'target_og' => '1.050',
            'target_fg' => '1.010',
            'notes' => null,
            'is_public' => false,
        ], ...$overrides];
    }

    public function testInsertReturnsNewIdAndRowIsFetchableById(): void
    {
        $repo = new RecipeRepository(new FakeDb());

        $id = $repo->insert($this->sampleData());
        $row = $repo->findById($id);

        $this->assertNotNull($row);
        $this->assertSame('Session IPA', $row['name']);
        $this->assertSame('beer', $row['category']);
        $this->assertSame(0, $row['is_public']);
    }

    public function testInsertNormalizesIsPublicToInteger(): void
    {
        $repo = new RecipeRepository(new FakeDb());

        $id = $repo->insert($this->sampleData(['is_public' => true]));

        $this->assertSame(1, $repo->findById($id)['is_public']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $repo = new RecipeRepository(new FakeDb());

        $this->assertNull($repo->findById(999));
    }

    public function testFindAllByUserIdReturnsOnlyThatUsersRecipes(): void
    {
        $repo = new RecipeRepository(new FakeDb());
        $repo->insert($this->sampleData(['user_id' => 1, 'name' => 'A']));
        $repo->insert($this->sampleData(['user_id' => 2, 'name' => 'B']));
        $repo->insert($this->sampleData(['user_id' => 1, 'name' => 'C']));

        $rows = $repo->findAllByUserId(1);

        $this->assertCount(2, $rows);
        $this->assertSame(['A', 'C'], array_column($rows, 'name'));
    }

    public function testUpdateOverwritesAllColumns(): void
    {
        $repo = new RecipeRepository(new FakeDb());
        $id = $repo->insert($this->sampleData(['name' => 'Original']));

        $repo->update($id, $this->sampleData(['name' => 'Renamed', 'category' => 'mead']));

        $row = $repo->findById($id);
        $this->assertSame('Renamed', $row['name']);
        $this->assertSame('mead', $row['category']);
    }

    public function testDeleteRemovesRow(): void
    {
        $repo = new RecipeRepository(new FakeDb());
        $id = $repo->insert($this->sampleData());

        $repo->delete($id);

        $this->assertNull($repo->findById($id));
    }

    public function testSetIsPublicTogglesFlag(): void
    {
        $repo = new RecipeRepository(new FakeDb());
        $id = $repo->insert($this->sampleData(['is_public' => false]));

        $repo->setIsPublic($id, true);
        $this->assertSame(1, $repo->findById($id)['is_public']);

        $repo->setIsPublic($id, false);
        $this->assertSame(0, $repo->findById($id)['is_public']);
    }

    public function testFindRecentPublicReturnsOnlyPublicRecipesOrderedByUpdatedAtDesc(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'is_public' => 0, 'updated_at' => '2026-06-01 00:00:00'],
            ['id' => 3, 'is_public' => 1, 'updated_at' => '2026-03-01 00:00:00'],
        ]);
        $repo = new RecipeRepository($db);

        $rows = $repo->findRecentPublic(10);

        $this->assertSame([3, 1], array_column($rows, 'id'));
    }

    public function testFindRecentPublicRespectsLimit(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'is_public' => 1, 'updated_at' => '2026-02-01 00:00:00'],
            ['id' => 3, 'is_public' => 1, 'updated_at' => '2026-03-01 00:00:00'],
        ]);
        $repo = new RecipeRepository($db);

        $rows = $repo->findRecentPublic(1);

        $this->assertSame([3], array_column($rows, 'id'));
    }
}
