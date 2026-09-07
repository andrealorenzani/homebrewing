<?php

declare(strict_types=1);

namespace Tests\Unit\Batches;

use App\Batches\BatchRepository;
use App\Db\FakeDb;
use Tests\TestCase;

final class BatchRepositoryTest extends TestCase
{
    private function sampleData(array $overrides = []): array
    {
        return [...[
            'recipe_id' => 1,
            'user_id' => 1,
            'label' => 'Batch 1',
            'status' => 'planning',
            'started_at' => '2026-01-01',
            'is_public' => false,
        ], ...$overrides];
    }

    public function testInsertReturnsNewIdAndRowIsFetchableById(): void
    {
        $repo = new BatchRepository(new FakeDb());

        $id = $repo->insert($this->sampleData());
        $row = $repo->findById($id);

        $this->assertNotNull($row);
        $this->assertSame('Batch 1', $row['label']);
        $this->assertSame('planning', $row['status']);
        $this->assertSame(0, $row['is_public']);
    }

    public function testInsertNormalizesIsPublicToInteger(): void
    {
        $repo = new BatchRepository(new FakeDb());

        $id = $repo->insert($this->sampleData(['is_public' => true]));

        $this->assertSame(1, $repo->findById($id)['is_public']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $repo = new BatchRepository(new FakeDb());

        $this->assertNull($repo->findById(999));
    }

    public function testFindAllByUserIdReturnsOnlyThatUsersBatches(): void
    {
        $repo = new BatchRepository(new FakeDb());
        $repo->insert($this->sampleData(['user_id' => 1, 'label' => 'A']));
        $repo->insert($this->sampleData(['user_id' => 2, 'label' => 'B']));
        $repo->insert($this->sampleData(['user_id' => 1, 'label' => 'C']));

        $rows = $repo->findAllByUserId(1);

        $this->assertCount(2, $rows);
        $this->assertSame(['A', 'C'], array_column($rows, 'label'));
    }

    public function testUpdateOverwritesAllColumns(): void
    {
        $repo = new BatchRepository(new FakeDb());
        $id = $repo->insert($this->sampleData(['label' => 'Original']));

        $repo->update($id, $this->sampleData(['label' => 'Renamed', 'status' => 'fermenting']));

        $row = $repo->findById($id);
        $this->assertSame('Renamed', $row['label']);
        $this->assertSame('fermenting', $row['status']);
    }

    public function testDeleteRemovesRow(): void
    {
        $repo = new BatchRepository(new FakeDb());
        $id = $repo->insert($this->sampleData());

        $repo->delete($id);

        $this->assertNull($repo->findById($id));
    }

    public function testSetIsPublicTogglesFlag(): void
    {
        $repo = new BatchRepository(new FakeDb());
        $id = $repo->insert($this->sampleData(['is_public' => false]));

        $repo->setIsPublic($id, true);
        $this->assertSame(1, $repo->findById($id)['is_public']);

        $repo->setIsPublic($id, false);
        $this->assertSame(0, $repo->findById($id)['is_public']);
    }
}
