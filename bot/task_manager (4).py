# task_manager.py
import asyncio
import heapq
import time
from typing import Any, Callable, Dict, Optional
import logging

logger = logging.getLogger(__name__)


class TaskManager:

    def __init__(self):
        self.tasks: Dict[str, Dict] = {}
        self.priority_queue: list = []
        self.running = False
        self._lock = asyncio.Lock()
        self._stop_event = asyncio.Event()

    async def start(self):
        self.running = True
        self._stop_event.clear()

        while self.running and not self._stop_event.is_set():
            try:
                await self._process_tasks()
                await self._sleep_optimized()
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"Task processing error: {e}", exc_info=True)
                await asyncio.sleep(1)

    async def _process_tasks(self):
        now = time.time()
        tasks_to_run = []

        async with self._lock:
            for task_id, task in list(self.tasks.items()):
                if task['next_run'] <= now:
                    tasks_to_run.append((task_id, task))
                    if task['interval'] > 0:
                        task['next_run'] = now + task['interval']
                        heapq.heappush(self.priority_queue,
                                       (task['next_run'], task_id))
                    else:
                        del self.tasks[task_id]

        for task_id, task in tasks_to_run:
            try:
                result = task['func'](*task.get('args', []),
                                      **task.get('kwargs', {}))
                if asyncio.iscoroutine(result):
                    await result
                if task.get('callback'):
                    await task['callback'](result)
            except Exception as e:
                logger.error(f"Task {task_id} failed: {e}", exc_info=True)
                if task.get('retries', 0) > 0:
                    task['retries'] -= 1
                    task['next_run'] = now + task.get('retry_delay', 5)
                elif task.get('error_callback'):
                    await task['error_callback'](e)

    async def _sleep_optimized(self):
        now = time.time()
        if self.priority_queue:
            next_run_time, _ = self.priority_queue[0]
            sleep_time = max(0.01, min(0.5, next_run_time - now))
        else:
            sleep_time = 0.5

        try:
            await asyncio.wait_for(self._stop_event.wait(), timeout=sleep_time)
        except asyncio.TimeoutError:
            pass

    def add_task(self,
                 task_id: str,
                 func: Callable,
                 interval: float = 0,
                 delay: float = 0,
                 args: tuple = (),
                 kwargs: dict = None,
                 retries: int = 0,
                 retry_delay: float = 5,
                 callback: Optional[Callable] = None,
                 error_callback: Optional[Callable] = None):
        next_run = time.time() + delay
        task_data = {
            'func': func,
            'interval': interval,
            'next_run': next_run,
            'args': args,
            'kwargs': kwargs or {},
            'retries': retries,
            'retry_delay': retry_delay,
            'callback': callback,
            'error_callback': error_callback
        }

        self.tasks[task_id] = task_data
        if interval > 0:
            heapq.heappush(self.priority_queue, (next_run, task_id))

    def remove_task(self, task_id: str):
        if task_id in self.tasks:
            del self.tasks[task_id]

    async def stop(self):
        self.running = False
        self._stop_event.set()
        await asyncio.sleep(0.1)
