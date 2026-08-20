#!/usr/bin/env python3
"""Pin the deploy workflow's health-probe base.

WHY THIS EXISTS
---------------
The production health probe targets the WordPress host. That host is moving
off the apex (`bluecollarcrypto.io`) to `cms.bluecollarcrypto.io`, because the
apex becomes the Next.js frontend. bcc-core and bcc-trust were converted to
read the `PROD_HEALTH_BASE` repository variable on 2026-08-19; bcc-search was
missed, and kept the apex hardcoded.

That drift is silent and self-disguising. Once the apex serves Next.js the
probe's routes all 404 — and 404 is precisely this workflow's "the plugin is
gone or deactivated" signal, so a DNS cutover would present as a plugin fault
on every future production deploy.

Nothing about that is caught by PHP tests, so it is checked here.

WHAT IS PROVEN
--------------
  1. deploy.yml is valid YAML
  2. the production base comes from `vars.PROD_HEALTH_BASE`
  3. the production path does not hardcode the apex, and has no fallback to it
  4. the staging base is unchanged
  5. a missing/empty variable fails BEFORE anything is written to the server
  6. every `run:` block is syntactically valid shell

SELF-TEST
---------
`--self-test` mutates a copy of the workflow and asserts each check FAILS on
the mutation and PASSES on the original. A checker that silently matches
nothing reports success just as loudly as one that works; the mutations are
what tell the two apart.
"""

from __future__ import annotations

import re
import subprocess
import sys

from pathlib import Path

import yaml

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = REPO_ROOT / ".github" / "workflows" / "deploy.yml"

APEX = "https://bluecollarcrypto.io"
STAGING_BASE = "https://stage.bluecollarcrypto.io"
PROD_VAR = "PROD_HEALTH_BASE"
PROBE_STEP = "Health probe (whole plugin trio)"
GUARD_STEP = "Require a production health base"
DEPLOY_STEP_MARKER = "rsync"

# `${{ … }}` is not shell. Blank it out before handing a run block to `bash -n`,
# the same way a linter would, so the check tests the script and not the
# templating.
GHA_EXPR = re.compile(r"\$\{\{.*?\}\}", re.DOTALL)



class Failure(Exception):
    pass


def load(text: str) -> dict:
    try:
        doc = yaml.safe_load(text)
    except yaml.YAMLError as exc:  # check 1
        raise Failure(f"deploy.yml is not valid YAML: {exc}") from exc
    if not isinstance(doc, dict) or "jobs" not in doc:
        raise Failure("deploy.yml has no top-level `jobs` mapping")
    return doc


def steps_of(doc: dict) -> list[dict]:
    job = (doc.get("jobs") or {}).get("deploy")
    if not isinstance(job, dict):
        raise Failure("deploy.yml has no `deploy` job")
    steps = job.get("steps")
    if not isinstance(steps, list):
        raise Failure("the `deploy` job has no `steps` list")
    return [s for s in steps if isinstance(s, dict)]


def find_step(steps: list[dict], name: str) -> tuple[int, dict]:
    for i, step in enumerate(steps):
        if step.get("name") == name:
            return i, step
    raise Failure(f"no step named {name!r} in the deploy job")


def index_of_marker(steps: list[dict], marker: str) -> int:
    for i, step in enumerate(steps):
        if marker in str(step.get("run") or ""):
            return i
    raise Failure(f"no step whose run block contains {marker!r}")


def rendered(step: dict) -> str:
    """A step's `run` plus its `env` values — the text that decides the base."""
    env = step.get("env") or {}
    env_text = "\n".join(f"{k}={v}" for k, v in env.items()) if isinstance(env, dict) else ""
    return f"{env_text}\n{step.get('run') or ''}"


def check(text: str) -> list[str]:
    doc = load(text)  # check 1
    steps = steps_of(doc)
    _, probe = find_step(steps, PROBE_STEP)
    probe_text = rendered(probe)

    # check 2 — the production base is sourced from the repository variable.
    if f"vars.{PROD_VAR}" not in probe_text:
        raise Failure(
            f"the {PROBE_STEP!r} step never reads `vars.{PROD_VAR}`; "
            "the production base is not variable-driven"
        )

    # check 3 — no apex anywhere in the probe, as a value or as a fallback.
    # Matched with a boundary so `https://cms.bluecollarcrypto.io` and
    # `https://stage.bluecollarcrypto.io` do not count as the apex.
    apex_hits = [
        line.strip()
        for line in probe_text.splitlines()
        if re.search(re.escape(APEX) + r"(?![A-Za-z0-9.-])", line)
    ]
    if apex_hits:
        raise Failure(
            f"the {PROBE_STEP!r} step still references the apex {APEX} "
            f"(the frontend host): {apex_hits}"
        )

    # check 4 — staging is untouched.
    if STAGING_BASE not in probe_text:
        raise Failure(f"the staging base {STAGING_BASE} is no longer in the probe step")

    # check 5 — a missing variable fails before anything reaches the server.
    guard_idx, guard = find_step(steps, GUARD_STEP)
    guard_text = rendered(guard)
    if f"vars.{PROD_VAR}" not in guard_text:
        raise Failure(f"the {GUARD_STEP!r} step does not read `vars.{PROD_VAR}`")
    if "exit 1" not in guard_text:
        raise Failure(f"the {GUARD_STEP!r} step never fails the job")
    if "production" not in str(guard.get("if") or ""):
        raise Failure(f"the {GUARD_STEP!r} step is not gated on the production environment")
    deploy_idx = index_of_marker(steps, DEPLOY_STEP_MARKER)
    if guard_idx >= deploy_idx:
        raise Failure(
            f"{GUARD_STEP!r} runs at step {guard_idx} but the rsync is step "
            f"{deploy_idx} — a missing variable would be caught only after the "
            "plugin was already written to the server"
        )

    # check 6 — every run block is valid shell.
    bad = []
    for step in steps:
        script = step.get("run")
        if not isinstance(script, str):
            continue
        # Fed on stdin rather than via a temp file: `bash -n` reads stdin when
        # given no file argument, and that avoids handing bash a path its host
        # may not be able to open.
        #
        # BINARY stdin, deliberately. With text=True, Python opens the pipe in
        # text mode and a Windows dev box rewrites every "\n" to "\r\n" on the
        # way in. bash then reads the CR as part of the token and reports
        # `syntax error near unexpected token $'do\r'` — indistinguishable, in
        # the output, from a genuine error in this workflow. Encoding here
        # keeps the bytes bash sees identical on every platform.
        proc = subprocess.run(
            ["bash", "-n"],
            input=GHA_EXPR.sub("GHA_EXPR", script).encode("utf-8"),
            capture_output=True,
            timeout=30,
        )
        if proc.returncode != 0:
            stderr = proc.stderr.decode("utf-8", "replace").strip()
            bad.append(f"{step.get('name') or '<unnamed>'}: {stderr}")
    if bad:
        raise Failure("shell syntax errors in run blocks: " + "; ".join(bad))

    return [
        "deploy.yml parses as YAML",
        f"production base reads vars.{PROD_VAR}",
        f"production path does not reference the apex {APEX}",
        f"staging base still {STAGING_BASE}",
        f"{GUARD_STEP!r} fails before the rsync (step {guard_idx} < {deploy_idx})",
        f"all {sum(1 for s in steps if isinstance(s.get('run'), str))} run blocks are valid shell",
    ]


# --------------------------------------------------------------------------
# Self-test
# --------------------------------------------------------------------------

MUTATIONS: list[tuple[str, str, str]] = [
    (
        "apex restored as the production base",
        'BASE="$PROD_HEALTH_BASE"',
        f'BASE="{APEX}"',
    ),
    (
        "variable replaced by an apex fallback",
        "PROD_HEALTH_BASE: ${{ vars.PROD_HEALTH_BASE }}",
        f"PROD_HEALTH_BASE: {APEX}",
    ),
    (
        "staging base changed",
        f'BASE="{STAGING_BASE}"',
        'BASE="https://example.invalid"',
    ),
    (
        "pre-deploy guard renamed away",
        f"- name: {GUARD_STEP}",
        "- name: Something else entirely",
    ),
    (
        "guard no longer fails the job",
        "            exit 1\n          fi\n          echo \"production health base:",
        "            :\n          fi\n          echo \"production health base:",
    ),
    (
        "shell syntax broken",
        "          probe () {",
        "          probe () {{",
    ),
    ("YAML broken", "jobs:", "jobs:\n  : : :"),
]


def self_test(text: str) -> int:
    failures = 0

    # must-not-flag: the real file passes.
    try:
        for line in check(text):
            print(f"    ok   {line}")
    except Failure as exc:
        print(f"    FAIL baseline workflow does not pass its own check: {exc}")
        return 1

    # must-flag: every mutation is caught.
    for label, old, new in MUTATIONS:
        if old not in text:
            print(f"    FAIL mutation {label!r} did not apply — anchor missing: {old!r}")
            failures += 1
            continue
        mutated = text.replace(old, new, 1)
        if mutated == text:
            print(f"    FAIL mutation {label!r} changed nothing")
            failures += 1
            continue
        try:
            check(mutated)
        except Failure:
            print(f"    ok   caught: {label}")
        except Exception as exc:  # noqa: BLE001 - any refusal counts as caught
            print(f"    ok   caught ({type(exc).__name__}): {label}")
            del exc
        else:
            print(f"    FAIL NOT caught: {label}")
            failures += 1

    return failures


def main() -> int:
    text = WORKFLOW.read_text(encoding="utf-8")

    if "--self-test" in sys.argv:
        print("self-test (must-not-flag baseline, then must-flag mutations):")
        failures = self_test(text)
        print("SELF-TEST GREEN" if failures == 0 else f"SELF-TEST: {failures} failure(s)")
        return 1 if failures else 0

    try:
        for line in check(text):
            print(f"  ok   {line}")
    except Failure as exc:
        print(f"::error::deploy health-base guard: {exc}")
        return 1
    print("PASS deploy health-base guard")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
