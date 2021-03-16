<?hh // strict

/**
 * A set of primitives for doing cpu-bound work in parallel
 * This uses the xbox task runner framework to execute tasks in separate threads
 * This is different from shelling out to a separate process, because
 * the xbox threads share the JIT with the parent process while having a separate data model
 *
 * As of this writing, this library is intended for use in parallelizing things like tests and linters
 * It is not hardened for usage on webservers, in particular due to the need to explicitly set ini values outside of HHVM
 */
namespace Parallel;
use namespace \HH\Lib\{Str, Vec, C};

/**
 * Entry point for parallel execution of CPU bound work
 * This doesn't have <<__EntryPoint>> because xbox wants to call it via hhvm.xbox.process_message_func
 */
function parallel_process_xbox_task(string $task_serialized): mixed {
  # this file can be used as an entry point for processing xbox requests, so we need to load the autoloader in that case
  require_once(__DIR__.'/../vendor/autoload.hack');
  \Facebook\AutoloadMap\initialize();
  $success = null;
  $task = \unserialize($task_serialized) as XboxTask<_, _>;
  return $task->execute();
}

/**
 * Xbox Task to check experiments in a child thread
 */
final class ExperimentsApcTask extends XboxTask<null, void> {

  public function execute(): void {
    $success = false;
    $big_file = \apc_fetch("big_nested_file", inout $success);
    $json = \json_decode($big_file, true);
    $experiments = $json['experiments'];

  }

}

/**
 * Base class for parallel tasks to extend
 * extend this and implement execute
 * You MUST define this task somewhere that can be autoloaded, such as include/
 * or hard-require that file above
 */
abstract class XboxTask<T, TRet> {
  final public function __construct(
    protected arraykey $id,
    protected T $payload,
  ) {}

  abstract public function execute(): TRet;

  final public function getID(): arraykey {
    return $this->id;
  }
}

/**
 * Invoke XboxTask instances in parallel
 */
final class Scheduler {

  /**
   * xbox configuration settings are unfortunately not settable with ini_set() at present
   * this will likely change, but for now it means we need to rely on them already being set
   * if they aren't set properly the tasks won't work, so let's throw exceptions if so
   */
  protected static function validateConfig(): void {
    $request_init_document = \ini_get(
      'hhvm.xbox.server_info.request_init_document',
    );
    if (!Str\contains($request_init_document, 'src/lib_parallel.php')) {

      throw new \Exception(
        "Parallelism will not work unless hhvm.xbox.server_info.request_init_document is set to include/lib_parallel.php",
      );
    }

    if (
      \ini_get('hhvm.xbox.process_message_func') !==
        'Parallel\parallel_process_xbox_task'
    ) {
      throw new \Exception(
        'Parallelism will not work unless hhvm.xbox.process_message_func is set to Parallel\parallel_process_xbox_task',
      );
    }

    if (
      !((int)Str\to_int(\ini_get('hhvm.xbox.server_info.thread_count')) > 1)
    ) {
      throw new \Exception(
        'Parallelism will not work unless hhvm.xbox.server_info.thread_count is >1',
      );
    }
  }
  /**
   * Pass a dict of tasks to invoke in parallel,
   * returns results keyed by the same key as the dict
   */
  public static function invokeParallel<Tk as arraykey, TTask, TRet>(
    dict<Tk, XboxTask<TTask, TRet>> $tasks,
  ): dict<Tk, TRet> {
    self::validateConfig();

    if (C\is_empty($tasks)) return dict[];

    $running_tasks = dict[];
    $results = dict[];
    # xbox does its own queueing, we don't need to try to manage the size of the queue
    # max queue depth is PHP_INT_MAX with xbox, so we can just enqueue every job and let it handle the details
    foreach ($tasks as $key => $task) {
      # this returns a resource which can be used to check the status of the task
      $running_tasks[$key] = \xbox_task_start(\serialize($task));
    }

    while (!C\is_empty($running_tasks)) {
      foreach ($running_tasks as $key => $task) {
        if (\xbox_task_status($task)) {
          $task_result = null;
          $task_code = \xbox_task_result($task, 0, inout $task_result);
          if ($task_code !== 200) {
            throw new \Exception(
              "Parallel task $key failed with code $task_code and message: $task_result",
            );
          }

          $results[$key] = $task_result;
          unset($running_tasks[$key]);
        }
      }
      # sleep 1 millisecond before checking again
      \HH\Asio\join(\HH\Asio\usleep(1000));
    }
    /* HH_FIXME[4110] result type must be TRet */
		return $results;
  }

  /**
   * Invokes an xbox task asynchronously
   * Checks each millisecond if the task is completed and yields
   * control if not each time
   */
  public static async function invokeAsync<TTask, TRet>(
    XboxTask<TTask, TRet> $task,
  ): Awaitable<TRet> {
    self::validateConfig();
    $task = \xbox_task_start(\serialize($task));
    $task_result = null;

    while (true) {
      if (\xbox_task_status($task)) {
        $task_result = null;
        $task_code = \xbox_task_result($task, 0, inout $task_result);
        if ($task_code !== 200) {
          throw new \Exception(
            "Parallel task failed with code $task_code and message: $task_result",
          );
        }
        break;
      }

      #
      # sleep 10 milliseconds before checking again
      # (more importantly, yields control allowing other code to execute)
      # HHAST_IGNORE_ERROR[DontAwaitInALoop]
      #
      await \HH\Asio\usleep(10000);
    }
    /* HH_FIXME[4110] result type must be TRet */
		return $task_result;
  }
}
