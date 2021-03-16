#!/bin/bash

time hhvm -d hhvm.xbox.server_info.request_init_document='src/lib_parallel.php' -d hhvm.xbox.process_message_func='Parallel\parallel_process_xbox_task' -d hhvm.xbox.server_info.thread_count=8 src/experiment_apc_harness.hack