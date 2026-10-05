"""Where the action's script is, and the import path that reaches it.

The script lives under resources/, because GitHub serves the action as the
archive git makes of it, which leaves out .github. Import this module before
`import gate_action`.
"""
import os
import sys

PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "resources", "action", "gate_action.py")

sys.path.insert(0, os.path.dirname(PATH))
