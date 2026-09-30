# BigBlueButton — test results log

One section per test run. Keep the newest at the top.

## Template

```
### <date> — <module version> / library <x.y.z> — <Drupal>/<PHP>
- Automated (group bigbluebutton): <pass>/<total>
- Live (group bigbluebutton_live): <pass>/<total> or skipped
- Notes: <what failed and why>
```

## Runs

### (pending) baseline — bigbluebutton 1.0.x-dev / library 2.0.13
Run the kit against the current module and record the result here. Security
checks (BBB-01, BBB-02, BBB-08, BBB-13) are expected to fail; this is the
baseline the fixes are measured against.

### (pending) after upgrade — bigbluebutton <fixed> / library 3.0.x
Acceptance run. Target: all automated and live checks green, no stored
meeting passwords, upgrade path (`drush updb`) clean.
