<?php

declare(strict_types=1);

namespace Tests\Unit\Batches;

use App\Batches\BatchLogEntryRepository;
use App\Db\FakeDb;
use Tests\TestCase;

final class BatchLogEntryRepositoryTest extends TestCase
{
    private function sampleData(array $overrides = []): array
    {
        return [...[
            'batch_id' => 1,
            'entry_date' => '2026-01-05',
            'note' => 'Smells great.',
            'specific_gravity' => '1.050',
            'acidity_ph' => '4.20',
            'temperature' => '20.00',
            'temperature_unit' => 'C',
            'stage_vessel' => 'Primary',
        ], ...$overrides];
    }

    public function testInsertReturnsNewIdAndRowIsFetchableById(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());

        $id = $repo->insert($this->sampleData());
        $row = $repo->findById($id);

        $this->assertNotNull($row);
        $this->assertSame('2026-01-05', $row['entry_date']);
        $this->assertSame('1.050', $row['specific_gravity']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());

        $this->assertNull($repo->findById(999));
    }

    public function testFindAllByBatchIdReturnsOnlyThatBatchsEntries(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());
        $repo->insert($this->sampleData(['batch_id' => 1, 'entry_date' => '2026-01-01']));
        $repo->insert($this->sampleData(['batch_id' => 2, 'entry_date' => '2026-01-02']));
        $repo->insert($this->sampleData(['batch_id' => 1, 'entry_date' => '2026-01-03']));

        $rows = $repo->findAllByBatchId(1);

        $this->assertCount(2, $rows);
    }

    public function testFindAllByBatchIdOrdersByEntryDateAscendingRegardlessOfInsertionOrder(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());
        // Insert deliberately out of chronological order.
        $repo->insert($this->sampleData(['batch_id' => 1, 'entry_date' => '2026-01-10', 'note' => 'third']));
        $repo->insert($this->sampleData(['batch_id' => 1, 'entry_date' => '2026-01-01', 'note' => 'first']));
        $repo->insert($this->sampleData(['batch_id' => 1, 'entry_date' => '2026-01-05', 'note' => 'second']));

        $rows = $repo->findAllByBatchId(1);

        $this->assertSame(['first', 'second', 'third'], array_column($rows, 'note'));
    }

    public function testUpdateOverwritesAllColumns(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());
        $id = $repo->insert($this->sampleData(['note' => 'Original']));

        $repo->update($id, $this->sampleData(['note' => 'Updated', 'entry_date' => '2026-02-01']));

        $row = $repo->findById($id);
        $this->assertSame('Updated', $row['note']);
        $this->assertSame('2026-02-01', $row['entry_date']);
    }

    public function testDeleteRemovesRow(): void
    {
        $repo = new BatchLogEntryRepository(new FakeDb());
        $id = $repo->insert($this->sampleData());

        $repo->delete($id);

        $this->assertNull($repo->findById($id));
    }
}
