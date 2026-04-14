# ShouldQueue

### Interface / Signature
```php
namespace TurboExcel\Import\Concerns;

interface ShouldQueue
{
}
```

### Purpose
Marker interface that tells Turbo-Excel to process the import in the background using your application's Queue workers.

### Detailed Documentation
When an import implements `ShouldQueue`, calling `TurboExcel::import(...)` will not block the request. Instead:

1.  The file is scanned (headers validated).
2.  The file is logically split into segments (chunks) based on your `chunkSize`.
3.  A Laravel **Batch** of jobs is dispatched to the queue.
4.  The method returns a `Batch` object immediately, which you can use to track progress.

### Customizing the Queue
By default, jobs are dispatched to your default queue connection and name. You can customize the target queue directly in your import class using either a property or a method.

#### Via Property
Define a `public $queue` property to set a static queue name:
```php
public $queue = 'imports-large';
```

#### Via Method
Define a `queue()` method for dynamic logic:
```php
public function queue(): string
{
    return 'imports-priority-' . $this->user->plan;
}
```

### Important
> [!WARNING]
> Because workers process chunks in parallel, you cannot use **ToCollection** with queued imports. Use **ToModel** or other persistence-based callbacks instead.

### Example
```php
use TurboExcel\Import\Concerns\ShouldQueue;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithChunkReading;

class BackgroundImport implements ToModel, ShouldQueue, WithChunkReading
{
    // Optional: Specify a custom queue
    public $queue = 'imports';

    public function model(array $row)
    {
        return new User(['name' => $row['name']]);
    }

    public function chunkSize(): int
    {
        return 5000;
    }
}
```
