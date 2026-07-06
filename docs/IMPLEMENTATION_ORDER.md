# Implementation Order

The canonical, ordered implementation queue lives in the pinned GitHub tracking issue
**[#141](https://github.com/FJCF76/PromptingPress/issues/141)** — the single source of truth
(live labels + `depends-on` metadata + the `v1.0.0` milestone).

This file is intentionally **not** maintained as a mirror. A duplicated in-repo copy only
drifts from the live issue (that is exactly what #203 was), and the loop already selects work
from live GitHub state via `gh`, never from this file. Go to **#141**.
