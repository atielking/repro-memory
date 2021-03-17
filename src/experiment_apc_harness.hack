use namespace HH\Lib\Vec;

<<__EntryPoint>>
async function experiment_apc_harness(): Awaitable<void> {
  require_once(__DIR__.'/../vendor/autoload.hack');
  Facebook\AutoloadMap\initialize();
  $contents = \file_get_contents('data/test.json');
  apc_store("big_nested_file", $contents);
  await Vec\map_async(
    Vec\range(0, 1000),
    async $i ==> check_experiments_async((string)$i),
  );
}


async function check_experiments_async(string $id): Awaitable<void> {
  $task = new Parallel\ExperimentsApcTask($id, null);
  echo "invoking task $id\n";
  await Parallel\Scheduler::invokeAsync($task);
  echo "invoked task $id\n";
}
