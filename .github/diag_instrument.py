import re, sys
p = 'vendor/pestphp/pest-plugin-mutate/src/MutationTest.php'
s = open(p).read()
log = r'''
    private function diagLog(string $what): void
    {
        $file = getenv('DIAG_SHARD_LOG');
        if (! is_string($file) || $file === '') { return; }
        static $cmd = false;
        $p = $this->process;
        $line = sprintf("=== %s %.2fs limit=%s exit=%s file=%s\n", $what, microtime(true) - (float) $this->start, (string) $p->getTimeout(), var_export($p->isRunning() ? null : $p->getExitCode(), true), $this->mutation->file->getRealPath());
        if (! $cmd) { $cmd = true; $line .= 'CMD ' . $p->getCommandLine() . "\n"; }
        if ($what !== 'escaped') { $line .= substr($p->getOutput(), -3000) . "\n--- err\n" . substr(str_replace("\x06", '<B>', $p->getErrorOutput()), -1500) . "\n"; }
        file_put_contents($file, $line, FILE_APPEND);
    }
'''
s = s.replace("    private function calculateTimeout(): int", log + "\n    private function calculateTimeout(): int", 1)
s = s.replace("        } catch (ProcessTimedOutException) {\n", "        } catch (ProcessTimedOutException) {\n            $this->diagLog('timeout');\n", 1)
s = s.replace("        if ($this->process->isSuccessful()) {\n            $this->updateResult(MutationTestResult::Untested);", "        if ($this->process->isSuccessful()) {\n            $this->diagLog('escaped');\n            $this->updateResult(MutationTestResult::Untested);", 1)
s = s.replace("        $this->updateResult(MutationTestResult::Tested);", "        $this->diagLog('tested');\n        $this->updateResult(MutationTestResult::Tested);", 1)
assert s.count('diagLog(') == 4, s.count('diagLog(')
open(p, 'w').write(s)
print('instrumented')
