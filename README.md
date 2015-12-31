# JobDaemon
PHP implementation of a daemon to parallelize work under multiple forked
child processes.

While there are other mechanisms (gearman, PECL pthreads) to parallelize
work with PHP, this solution may have advantages for some architectures
where simplicity and ease of implementation are of major importance.

This may not be the right solution to handle a very high volume of child
processes starting and stopping frequently.  With that said, one could
implement a "worker" pattern, where each child process runs multiple
"jobs" or requests in a loop before it decides to shut itself down.

This is well-suited for ETL applications, where the biggest bottleneck is
a database or calling some CPU-intensive program (image/video processing),
and you want to use PHP to implement the controlling & launching code.

Ideally, all your requires/includes should run when the daemon parent
process starts, such that fork()'ing a child doesn't incur the overhead
of PHP re-compiling the application into bytecode.  In other words,
use of an autoloader will reduce efficiency when launching new child
processes.

There are only 3 methods to integrate with your application:
1) loadConfig(): Optional, to load or update any app configuration.
2) getNextJob(): Called before launching a child process, to return either
                 metadata about the next job, or NULL if no job ready.
3) childRun(): Entrypoint for your application to run a job, given the
               metadata from above.

Your application must extend the JobDaemon class, instantiate it,
optionally call some methods to tweak settings (like setMaxChildren),
and then call the daemonRun() method.
When daemonRun() returns, your application can handle any extra post-run
cleanup required.

A demo sample application is included that illustrates all of the above.

Features:
- Truly daemonized at startup by parent forking itself and detaching
  from the TTY, and creating a PID file (only 1 daemon instance on server).
- Can run as a non-root user, as root, or optionally use seteuid() to a
  different user (useful when auto-launched at system boot time).
- Handles SIGTERM / SIGQUIT signals: no more children spawned, optionally
  sends signal to children, and waits for all child processes to die.
- Handles SIGHUP: calls user-defineable loadConfig() method.
- Full setup as a Un*x daemon with an init.d script
- Uses "daemon" logging level, to separate out daemon debug logging
  from application logging.
