p = 'vendor/symfony/process/Process.php'
s = open(p).read()
hook = r'''        if (null !== $this->timeout && $this->timeout < microtime(true) - $this->starttime) {
            $diagFile = getenv('DIAG_SHARD_LOG');
            if (is_string($diagFile) && $diagFile !== '') {
                static $diagCmd = false;
                $pid = $this->getPid();
                $tree = $pid === null ? '' : (string) shell_exec('ps -o pid,ppid,etimes,pcpu,rss,stat,args --forest -g $(ps -o sid= -p ' . (int) $pid . ') 2>/dev/null | cut -c1-300');
                $line = sprintf("=== timeout ran %.2fs limit %.2f load %s\n", microtime(true) - $this->starttime, $this->timeout, implode(',', array_map(fn ($l) => sprintf('%.1f', $l), sys_getloadavg() ?: [])));
                if (! $diagCmd) { $diagCmd = true; $line .= 'CMD ' . substr($this->getCommandLine(), 0, 4000) . "\n"; }
                $line .= "TREE\n" . $tree . "OUT\n" . substr($this->getOutput(), -2500) . "\nERR beats=" . substr_count($this->getErrorOutput(), "\x06") . "\n" . substr(str_replace("\x06", '', $this->getErrorOutput()), -1500) . "\n";
                file_put_contents($diagFile, $line, FILE_APPEND);
            }
            $this->stop(0);

            throw new ProcessTimedOutException($this, ProcessTimedOutException::TYPE_GENERAL);
        }
'''
old = '''        if (null !== $this->timeout && $this->timeout < microtime(true) - $this->starttime) {
            $this->stop(0);

            throw new ProcessTimedOutException($this, ProcessTimedOutException::TYPE_GENERAL);
        }
'''
assert old in s
s = s.replace(old, hook, 1)
open(p, 'w').write(s)
print('instrumented')
