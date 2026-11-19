#!/usr/bin/env python3
"""Maintain Webasyst's tracked build number without a second CI commit."""
import re
import subprocess
import sys
from pathlib import Path

BUILD = "lib/config/build.php"


def git(*args, optional=False):
    result = subprocess.run(["git", *args], capture_output=True, text=True)
    if result.returncode and not optional:
        raise RuntimeError(result.stderr.strip())
    return result.stdout.strip() if result.returncode == 0 else None


def read_build(ref):
    text = git("show", f"{ref}:{BUILD}", optional=True)
    if text is None:
        # Commits before the introduction of build.php have build zero.
        return 0
    match = re.fullmatch(r"<\?php\s+return ([0-9]+);\s*", text)
    if not match:
        raise RuntimeError(f"Invalid {BUILD} at {ref}")
    return int(match[1])


def bump():
    if git("ls-files", "-u"):
        raise RuntimeError("Resolve merge conflicts before incrementing the build")
    head = git("rev-parse", "--verify", "HEAD", optional=True)
    parents = [head] if head else []
    merge_head = Path(git("rev-parse", "--git-path", "MERGE_HEAD"))
    if merge_head.exists():
        parents.extend(merge_head.read_text().splitlines())
    number = max((read_build(parent) for parent in parents), default=0) + 1
    Path(BUILD).write_text(f"<?php\n\nreturn {number};\n")
    git("add", "--", BUILD)
    print(f"Webasyst build: {number}")


def check(ref):
    commit, *parents = git("rev-list", "--parents", "-n", "1", ref).split()
    number = read_build(commit)
    previous = max((read_build(parent) for parent in parents), default=0)
    if number <= previous:
        raise RuntimeError(f"Build {number} at {commit} must exceed parent build {previous}")
    print(f"{commit}: build {number} OK")


if __name__ == "__main__":
    try:
        if sys.argv[1:] == ["bump"]:
            bump()
        elif len(sys.argv) in (2, 3) and sys.argv[1] == "check":
            check(sys.argv[2] if len(sys.argv) == 3 else "HEAD")
        else:
            raise RuntimeError("Usage: python3 tools/build.py bump | check [commit]")
    except (RuntimeError, OSError) as error:
        sys.exit(str(error))
