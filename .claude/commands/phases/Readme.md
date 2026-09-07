# .claude/commands/phases/

`.claude/struct.md` expects one command file per development phase. Gomrok instead keeps a single
plan in **`.claude/docs/Phases.md`** (30 phases + a Status & execution tracking table). The files
here are thin pointers to that plan — do not duplicate phase scope or status into them.

- `Phase00Foundation.md`, `Phase01ProjectDiscovery.md`, `Phase02RepositoryBootstrap.md` — the
  generic names from `struct.md`, kept as skeletons with a note mapping them to Gomrok's phases.
- `PhaseTemplate.md` — the `phase-NN-[name].md` placeholder.
