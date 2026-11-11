"""Where the action's script is, and the import path that reaches it and the modules beside it.

The scripts live under resources/, because GitHub serves the action as the
archive git makes of it, which leaves out .github. Import this module before
`import gate_action`, `import installs` or `import run_jobs`.
"""
import os
import sys

PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "resources", "action", "gate_action.py")

sys.path.insert(0, os.path.dirname(PATH))
