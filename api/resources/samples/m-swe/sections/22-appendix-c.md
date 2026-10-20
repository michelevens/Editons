# Appendix C: Metric Definitions and Extraction Queries

This appendix gives the operational definitions used to compute the seven repository metrics reported in Chapter 4, and an example of the extraction logic. The data were extracted from the code review platform and the continuous integration pipeline into a staging database inside the company's network. Author identifiers were replaced by salted hashes before any query was run, and only aggregated, team-week records left the network (Section 3.10).

**Table C.1** Operational definitions of the repository metrics.

| Metric | Unit of aggregation | Inclusion rules | Exclusions | Computation |
|---|---|---|---|---|
| PRs merged per engineer-week | Team-week | PRs merged to a production branch; author a team member on the opening date | Automated dependency updates; PRs closed without merge | Merged PRs in week ÷ engineers active in week |
| Median PR cycle time | Team-period | Merged PRs | PRs closed without merge | Median of (merged_at − opened_at), in hours |
| Median changed lines per PR | Team-period | Merged PRs | Generated files, lock files, vendored code | Median of (lines added + lines deleted) |
| Mean review comments per PR | Team-period | Human-authored review comments on merged PRs | Bot comments; author's own replies | Total comments ÷ merged PRs |
| Security findings per 1,000 changed lines | Team-period, and two-week periods for Atlas | SAST findings of medium severity or above first raised on a merged PR | Findings suppressed as false positives before merge | Findings ÷ changed lines × 1,000, pooled over the period |
| PRs reverted within 14 days | Team-period | Merged PRs reverted wholly or partly by a commit merged within 14 days | Reverts of reverts | Reverted PRs ÷ merged PRs × 100 |
| Test lines changed per 100 production lines | Team-period | Changed lines in merged PRs | As for changed lines | Test-file lines ÷ non-test source lines × 100 |

*Note.* "Period" is the before window (2 September 2024–2 March 2025) or the after window (3 March–31 August 2025). Test files were identified by repository naming and directory conventions confirmed with each tech lead. Engineers were counted as active in a week if they were team members and not on recorded leave for the whole week.

The query below computes weekly throughput per team, the dependent variable in Equation (3.1). It assumes three staging tables: `pull_requests`, `team_membership` (one row per engineer and team, with start and end dates) and `engineer_weeks` (one row per team, week and count of active engineers).

```sql
-- Weekly PRs merged per engineer-week, by team (dependent variable for the ITS model)
WITH merged AS (
    SELECT
        tm.team,
        DATE_TRUNC('week', pr.merged_at) AS week_start,
        COUNT(*)                          AS prs_merged
    FROM pull_requests pr
    JOIN team_membership tm
      ON tm.author_hash = pr.author_hash
     AND pr.opened_at::date BETWEEN tm.start_date AND COALESCE(tm.end_date, DATE '2025-08-31')
    WHERE pr.merged_at IS NOT NULL
      AND pr.is_bot = FALSE
      AND pr.target_branch_is_production = TRUE
      AND pr.merged_at >= DATE '2024-09-02'
      AND pr.merged_at <  DATE '2025-09-01'
    GROUP BY tm.team, DATE_TRUNC('week', pr.merged_at)
)
SELECT
    ew.team,
    ew.week_start,
    COALESCE(m.prs_merged, 0)                          AS prs_merged,
    ew.active_engineers,
    COALESCE(m.prs_merged, 0)::numeric / ew.active_engineers AS prs_per_engineer_week,
    CASE WHEN ew.week_start >= DATE '2025-03-03' THEN 1 ELSE 0 END AS post_adoption
FROM engineer_weeks ew
LEFT JOIN merged m
  ON m.team = ew.team
 AND m.week_start = ew.week_start
ORDER BY ew.team, ew.week_start;
```

The other metrics were computed with queries of the same structure, substituting the relevant aggregate and the exclusions listed in Table C.1.
