<?php

declare(strict_types=1);

namespace Tests\Unit\Recipes;

use App\Db\FakeDb;
use App\Recipes\RecipeIngredientRepository;
use Tests\TestCase;

final class RecipeIngredientRepositoryTest extends TestCase
{
    public function testFindAllByRecipeIdReturnsEmptyArrayWhenNone(): void
    {
        $repo = new RecipeIngredientRepository(new FakeDb());

        $this->assertSame([], $repo->findAllByRecipeId(1));
    }

    public function testReplaceAllForRecipeInsertsRowsInSortOrder(): void
    {
        $repo = new RecipeIngredientRepository(new FakeDb());

        $repo->replaceAllForRecipe(1, [
            ['ingredient_type' => 'hop', 'name' => 'Citra', 'quantity' => '30', 'unit' => 'g', 'timing_note' => 'boil'],
            ['ingredient_type' => 'grain', 'name' => 'Pale Malt', 'quantity' => '4', 'unit' => 'kg', 'timing_note' => null],
        ]);

        $rows = $repo->findAllByRecipeId(1);

        $this->assertCount(2, $rows);
        $this->assertSame('Citra', $rows[0]['name']);
        $this->assertSame(0, $rows[0]['sort_order']);
        $this->assertSame('Pale Malt', $rows[1]['name']);
        $this->assertSame(1, $rows[1]['sort_order']);
    }

    public function testReplaceAllForRecipeOnlyAffectsThatRecipesRows(): void
    {
        $repo = new RecipeIngredientRepository(new FakeDb());
        $repo->replaceAllForRecipe(1, [
            ['ingredient_type' => 'other', 'name' => 'Untouched', 'quantity' => null, 'unit' => null, 'timing_note' => null],
        ]);

        $repo->replaceAllForRecipe(2, [
            ['ingredient_type' => 'fruit', 'name' => 'Cherry', 'quantity' => '1', 'unit' => 'kg', 'timing_note' => null],
        ]);

        $this->assertCount(1, $repo->findAllByRecipeId(1));
        $this->assertSame('Untouched', $repo->findAllByRecipeId(1)[0]['name']);
        $this->assertSame('Cherry', $repo->findAllByRecipeId(2)[0]['name']);
    }

    public function testReplaceAllForRecipeDeletesPreviousRowsBeforeInserting(): void
    {
        $repo = new RecipeIngredientRepository(new FakeDb());
        $repo->replaceAllForRecipe(1, [
            ['ingredient_type' => 'other', 'name' => 'Old', 'quantity' => null, 'unit' => null, 'timing_note' => null],
        ]);

        $repo->replaceAllForRecipe(1, [
            ['ingredient_type' => 'other', 'name' => 'New', 'quantity' => null, 'unit' => null, 'timing_note' => null],
        ]);

        $rows = $repo->findAllByRecipeId(1);
        $this->assertCount(1, $rows);
        $this->assertSame('New', $rows[0]['name']);
    }

    public function testReplaceAllForRecipeWithEmptyListClearsExistingRows(): void
    {
        $repo = new RecipeIngredientRepository(new FakeDb());
        $repo->replaceAllForRecipe(1, [
            ['ingredient_type' => 'other', 'name' => 'Old', 'quantity' => null, 'unit' => null, 'timing_note' => null],
        ]);

        $repo->replaceAllForRecipe(1, []);

        $this->assertSame([], $repo->findAllByRecipeId(1));
    }
}
