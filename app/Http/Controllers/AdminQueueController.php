<?php

namespace App\Http\Controllers;

use App\Services\QueueMonitor;
use Illuminate\Http\Request;

class AdminQueueController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    /**
     * The queue page. With ?partial=1 only the live part is rendered, for the page's auto-refresh.
     */
    public function index(Request $request)
    {
        $view = $request->boolean('partial') ? 'admin._queue-body' : 'admin.queue';

        return view($view, ['q' => $this->queue->snapshot()]);
    }

    public function retry(Request $request)
    {
        $request->validate(['uuid' => ['nullable', 'string', 'max:64']]);
        $count = $this->queue->retry($request->input('uuid'));

        return back()->with($count ? 'success' : 'error', $count ? "{$count} کار دوباره در صف قرار گرفت." : 'کاری برای تلاش دوباره نبود.');
    }

    public function forget(Request $request)
    {
        $request->validate(['uuid' => ['nullable', 'string', 'max:64']]);
        $count = $this->queue->forget($request->input('uuid'));

        return back()->with('success', "{$count} مورد از فهرست شکست‌ها پاک شد.");
    }

    public function cancel(Request $request)
    {
        $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'queue' => ['nullable', 'string', 'in:default,media']]);

        if (! $request->filled('id') && ! $request->filled('queue')) {
            return back()->with('error', 'کاری برای لغو مشخص نشده است.');
        }

        $count = $this->queue->cancel($request->filled('id') ? $request->integer('id') : null, $request->input('queue'));

        return back()->with($count ? 'success' : 'error', $count ? "{$count} کار منتظر لغو شد." : 'کار منتظری پیدا نشد (شاید همین حالا شروع شده).');
    }

    public function requeue(Request $request)
    {
        $count = $this->queue->requeueConversions($request->boolean('delete_original'));

        return back()->with($count ? 'success' : 'error', $count ? "{$count} ویدیوی تبدیل‌نشده در صف تبدیل قرار گرفت." : 'ویدیوی تبدیل‌نشده‌ای که در صف نباشد پیدا نشد.');
    }
}
