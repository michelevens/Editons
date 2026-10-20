# Chapter 4: Results: Repository and Survey Data

## 4.1 Introduction

This chapter reports the quantitative results of the study. It answers RQ1, which asks how measurable delivery and quality indicators changed in each team in the 26 weeks after adoption, and it contributes the survey evidence on patterns of use that forms one half of the answer to RQ2. The results are presented with as little interpretation as the reader needs to follow them; explanation of why the teams differed is deferred to Chapter 6, where these results are integrated with the interview findings of Chapter 5.

Section 4.2 describes the data available for analysis. Section 4.3 compares the seven repository metrics defined in Table 3.2 before and after adoption. Section 4.4 reports the interrupted time series estimates of the change in throughput, based on Equation (3.1). Section 4.5 examines the trajectory of security findings in the Atlas team, the one place where the after period did not behave as a single block. Section 4.6 reports the survey. Section 4.7 summarizes what the quantitative strand shows and what it leaves open.

## 4.2 Data available for analysis

The repository strand covers all merged PRs authored by members of the three teams in the 52 weeks from 2 September 2024 to 31 August 2025, divided at the adoption date of 3 March 2025 into a before period and an after period of 26 weeks each. Team membership was stable across the window, at 16 engineers in Atlas, 15 in Beacon and 13 in Cormorant, and all rates in this chapter are normalized either by engineer-weeks or by changed lines, so that weeks of reduced staffing, such as the December holidays, do not appear as changes in performance. All weeks of both periods are included; no team had a week without merged PRs.

The survey received 38 responses from the 44 engineers in the three teams, a response rate of 86%. Response was similar across teams: 14 of 16 in Atlas, 13 of 15 in Beacon and 11 of 13 in Cormorant. Because the survey was anonymous, it cannot be linked to repository records, and the two sources are therefore compared at team level only.

## 4.3 Repository metrics before and after adoption

Table 4.1 sets out the seven metrics for each team in the before and after periods. The table is the main descriptive result of the study, and the subsections that follow take its rows in groups.

**Table 4.1** Repository metrics before and after adoption, by team.

| Metric | Atlas | Beacon | Cormorant |
|---|---|---|---|
| PRs merged per engineer-week | 3.1 → 3.3 | 4.2 → 4.7 | 2.4 → 2.4 |
| Median PR cycle time (hours, open→merge) | 26.4 → 24.9 | 19.8 → 15.6 | 31.2 → 32.0 |
| Median changed lines per PR | 118 → 164 | 96 → 131 | 142 → 150 |
| Mean review comments per PR | 4.6 → 5.8 | 3.9 → 4.4 | 5.2 → 5.5 |
| Static-analysis security findings per 1,000 changed lines | 0.82 → 0.93 | 0.55 → 0.61 | 0.40 → 0.43 |
| PRs reverted within 14 days (%) | 1.9 → 2.2 | 2.4 → 2.3 | 1.6 → 1.7 |
| Test lines changed per 100 production lines | 41 → 52 | 28 → 31 | 22 → 24 |

*Note.* Each cell shows the before value (2 September 2024 to 2 March 2025) and the after value (3 March to 31 August 2025), 26 weeks each. Definitions are given in Table 3.2.

### 4.3.1 Throughput and cycle time

The clearest change in throughput was in Beacon. Its engineers merged 4.2 PRs per engineer-week before adoption and 4.7 after, an increase of 0.5, or about 12%. Atlas rose more modestly, from 3.1 to 3.3, an increase of about 6%. Cormorant did not change at all, at 2.4 PRs per engineer-week in both periods. Figure 4.1 shows the three pairs of values side by side.

**Figure 4.1** PRs merged per engineer-week before and after adoption.

```chart
{"type": "bar", "title": "PRs merged per engineer-week before and after adoption", "xLabel": "Team", "yLabel": "PRs merged per engineer-week", "data": "Team,Before,After\nAtlas,3.1,3.3\nBeacon,4.2,4.7\nCormorant,2.4,2.4"}
```

Cycle time followed the same ordering. Beacon's median time from opening a PR to merging it fell from 19.8 hours to 15.6, a reduction of 4.2 hours or about 21%, the largest change in cycle time in the study. Atlas's median fell by 1.5 hours, from 26.4 to 24.9, about 6%. Cormorant's median rose slightly, from 31.2 to 32.0 hours. Read together, the throughput and cycle time results place the three teams in a consistent order: Beacon clearly faster, Atlas slightly faster, and Cormorant unchanged or marginally slower.

It is worth noting at this point what the two measures do not show. Neither metric distinguishes between faster writing and faster review, and a team whose authors produced code more quickly but whose reviewers took longer could show little net change in cycle time. Section 4.3.2 suggests that this is part of what happened in Atlas.

### 4.3.2 Size of changes and review activity

PRs became larger in all three teams, but by very different amounts. The median number of changed lines per PR rose by 46 lines in Atlas (from 118 to 164, about 39%) and by 35 in Beacon (from 96 to 131, about 36%), while in Cormorant it rose by only 8 (from 142 to 150, about 6%). In the two teams whose PRs grew substantially, more code was therefore being submitted for review in each request.

Review activity rose with size, but not in proportion. Mean review comments per PR increased from 4.6 to 5.8 in Atlas, a rise of 1.2 comments or about 26%, the largest change in review activity in the study. Beacon's mean rose from 3.9 to 4.4, about 13%, and Cormorant's from 5.2 to 5.5, about 6%. The contrast between Atlas and Beacon is instructive. Their PRs grew by similar proportions, yet Atlas reviewers wrote roughly twice the proportional increase in comments that Beacon reviewers did. Since cycle time fell less in Atlas than in Beacon, these figures are consistent with reviewers in Atlas absorbing a larger share of the work of checking each change, a possibility examined from the engineers' side in Chapter 5.

Comments per PR are not comments per line. Because PRs grew faster than comment counts in both Atlas and Beacon, the density of review discussion per changed line fell in both teams. Whether this reflects more efficient review of routine code or thinner scrutiny of larger changes cannot be settled from the repository data alone; it is one of the questions carried forward to integration in Chapter 6.

### 4.3.3 Security findings and reverts

SAST findings of medium severity or above, normalized per 1,000 changed lines, rose in all three teams across the after period as a whole. Atlas rose from 0.82 to 0.93, Beacon from 0.55 to 0.61 and Cormorant from 0.40 to 0.43. In relative terms the rises were about 13% in Atlas, 11% in Beacon and 8% in Cormorant. The ordering of the teams on this metric was unchanged by adoption: Atlas, whose payment and payroll services contain the most code that the analyzer's rules are designed to inspect, had the highest rate in both periods, and Cormorant the lowest.

For Atlas, the 26-week after value conceals a marked change within the period, which Section 4.5 examines. The equivalent within-period analysis for Beacon and Cormorant showed no comparable break, and their after-period values are reported as single figures.

The proportion of PRs reverted within 14 days changed very little anywhere. Atlas moved from 1.9% to 2.2%, Beacon from 2.4% to 2.3% and Cormorant from 1.6% to 1.7%. Reverts are rare events, and differences of a few tenths of a percentage point over 26 weeks correspond to a handful of PRs in teams of this size. I therefore read these figures as showing no detectable change in the rate of failures serious enough to be reverted, rather than as small movements in either direction. In particular, Beacon's faster delivery was not accompanied by a higher revert rate.

### 4.3.4 Testing

The ratio of test lines to production lines changed rose in all three teams, most sharply in Atlas, from 41 to 52 test lines per 100 production lines, an increase of about 27%. Beacon rose from 28 to 31 and Cormorant from 22 to 24. Two readings of the rise are possible and are not mutually exclusive. Engineers may have used the assistant to write tests that they would previously have written more slowly or not at all, which is consistent with the survey finding in Section 4.6 that generating boilerplate and tests was the most common use. Alternatively, or additionally, teams may have demanded more tests for code they trusted less. The second reading is particularly plausible for Atlas, whose week-12 agreement made tests mandatory for some assistant-generated functions, and it is tested against the interview evidence in Chapter 5.

## 4.4 Interrupted time series estimates

The before and after comparison of Table 4.1 does not account for trends that were already under way. Table 4.2 reports, for each team, the estimated change in level of weekly PRs merged per engineer-week at adoption ($\beta_2$ in Equation (3.1)), with its 95% confidence interval, and the estimated change in weekly trend ($\beta_3$).

**Table 4.2** Interrupted time series estimates of level change.

| Team | Level change $\beta_2$ (PRs per engineer-week) | 95% CI | Slope change $\beta_3$ |
|---|---|---|---|
| Atlas | 0.18 | −0.05 to 0.41 | < 0.01 in absolute value |
| Beacon | 0.46 | 0.21 to 0.71 | < 0.01 in absolute value |
| Cormorant | 0.02 | −0.24 to 0.28 | < 0.01 in absolute value |

*Note.* One model per team, fitted to 52 weekly observations (26 before, 26 after adoption on 3 March 2025). Confidence intervals use Newey–West standard errors with a lag of two weeks.

The time series results sharpen the descriptive picture in three ways. First, Beacon's increase in throughput survives the adjustment for prior trend. The estimated level change of 0.46 PRs per engineer-week is close to the raw before and after difference of 0.5, which indicates that little of Beacon's increase can be attributed to a trend already present in the before period, and its confidence interval, from 0.21 to 0.71, excludes zero. In this sample, Beacon's throughput rose at adoption by an amount that is unlikely to reflect week-to-week noise alone.

Second, Atlas's estimated level change of 0.18 has a confidence interval running from −0.05 to 0.41. The data are compatible with a modest increase, which is what the point estimate suggests, but also with no change or a very small decrease. The study cannot claim that Atlas's throughput changed, and it would equally be wrong to claim that it did not; the honest reading is that any change was too small to be estimated precisely with 52 weeks of data from a team of 16.

Third, Cormorant's estimate of 0.02, with an interval from −0.24 to 0.28 centered almost exactly on zero, gives no indication of a change in level.

In all three teams the slope change $\beta_3$ was below 0.01 in absolute value, so there is no evidence that adoption altered the weekly trend in throughput. Where adoption was associated with a change, as in Beacon, the change took the form of a step at the time of adoption that then held steady, rather than a gradual increase as engineers became more practiced with the tool. This is worth noting because it runs against the expectation, common in discussions of new tooling, that benefits accumulate over months; Chapter 6 considers why the effect in Beacon may have been immediate.

## 4.5 Security findings in Atlas over time

Atlas was the only team whose after period contained a documented change in practice. At the close of week 12, on 26 May 2025, the team adopted a written agreement on the assistant's use with three provisions: (a) PRs in which the assistant had generated a substantial share of the code were to carry an "assistant-heavy" label; (b) any assistant-generated function touching money or personal data required accompanying tests; and (c) the assistant's chat was not to be used for cryptography or authentication code. Because a single after-period value would average across this change, I examined Atlas's security findings in two-week periods. Figure 4.2 shows the series, with periods −13 to −1 forming the before period and periods 1 to 13 the after period.

**Figure 4.2** Atlas security findings per 1,000 changed lines, two-week periods.

```chart
{"type": "line", "title": "Atlas security findings per 1,000 changed lines, two-week periods", "xLabel": "Two-week period relative to adoption", "yLabel": "Findings per 1000 changed lines", "data": "Period,Atlas findings per 1000 changed lines\n-13,0.79\n-12,0.85\n-11,0.81\n-10,0.84\n-9,0.80\n-8,0.83\n-7,0.78\n-6,0.86\n-5,0.82\n-4,0.81\n-3,0.84\n-2,0.80\n-1,0.83\n1,1.08\n2,1.17\n3,1.12\n4,1.19\n5,1.10\n6,1.18\n7,0.74\n8,0.70\n9,0.73\n10,0.69\n11,0.72\n12,0.70\n13,0.71"}
```

The series falls into three distinct phases. In the before period, findings varied within a narrow band between 0.78 and 0.86 per 1,000 changed lines, with a period-level mean of 0.82, the same as the before value in Table 4.1. In the first twelve weeks after adoption (periods 1 to 6), every period lay above the highest value of the before period, between 1.08 and 1.19, giving a mean of 1.14 for weeks 1 to 12, about 39% above the before level. In the fourteen weeks after the agreement (periods 7 to 13), the rate dropped at once to 0.74 and remained between 0.69 and 0.74, giving a mean of 0.71 for weeks 13 to 26, below the before level.

The 26-week after value of 0.93 in Table 4.1 is therefore an average of two quite different regimes. It is computed on all changed lines in the period, and because Atlas changed more code in weeks 1 to 12 than in weeks 13 to 26, it sits slightly above the simple average of the thirteen two-week values. Reading the 0.93 alone would suggest a moderate, persistent deterioration in Atlas's security profile; the two-week series instead shows a sharp early rise followed by a reduction to a level lower than before adoption.

Two cautions apply. The timing of the drop coincides exactly with the agreement, but the study has no second team that adopted a comparable agreement at a different time, so the coincidence cannot be separated from other changes that may have occurred in late May, such as engineers' growing familiarity with the tool. The abruptness of the change, a single step between periods 6 and 7 rather than a gradual decline, fits an explanation in terms of a deliberate change in practice better than one in terms of gradual learning, but this is an inference, not a demonstration. Second, the SAST rate measures what the analyzer's rules detect. A fall in detected findings is consistent with safer code, but it is also consistent with engineers avoiding the kinds of code, such as authentication logic, in which the analyzer finds most issues; provision (c) of the agreement would have this effect by design. The interview evidence in Chapter 5 is needed to choose between these readings.

## 4.6 Survey results

Table 4.3 summarizes the survey responses. As planned in Section 3.6.1, team-level values are reported for daily use, perceived productivity and the difficulty of reviewing teammates' PRs; the remaining items are reported for the pooled sample.

**Table 4.3** Survey responses by team.

| Item | Atlas (n = 14) | Beacon (n = 13) | Cormorant (n = 11) | All (n = 38) |
|---|---|---|---|---|
| Used the assistant daily, n (%) | 9 (64%) | 11 (85%) | 7 (64%) | 27 (71%) |
| "The assistant makes me more productive", mean | 3.7 | 4.2 | 3.4 | 3.8 (SD 0.9) |
| "Reviewing teammates' PRs has become harder", mean | 3.9 | 3.1 | 3.2 | 3.4 (SD 1.1) |
| "I review assistant-generated code more carefully than code I wrote", mean | — | — | — | 3.9 (SD 0.8) |
| "I trust suggestions for security-sensitive code", mean | — | — | — | 2.3 (SD 1.0) |
| "The assistant helps me learn unfamiliar APIs or languages", mean | — | — | — | 4.1 (SD 0.7) |

*Note.* Agreement items were rated from 1 (strongly disagree) to 5 (strongly agree). A dash indicates an item planned for pooled reporting only (Section 3.6.1). Purposes of use are reported in Section 4.6.1.

### 4.6.1 Adoption and purposes of use

Use of the assistant was widespread six months after the rollout. Twenty-seven of the 38 respondents (71%) reported using it daily. The proportion was highest in Beacon, at 11 of 13 (85%), and equal in Atlas (9 of 14) and Cormorant (7 of 11), at 64% each.

The purposes respondents selected, in a multi-select item, show that use was concentrated in routine work. Generating boilerplate and tests was selected by 32 of the 38 respondents, and looking up unfamiliar APIs by 29. Refactoring (18) and debugging (17) were each chosen by slightly under half. Writing SQL was chosen by 12 respondents, of whom 9 were in Cormorant, so that 9 of Cormorant's 11 respondents used the assistant for SQL against only 3 respondents in the other two teams combined. Documentation was the least selected purpose, at 11. The pattern resembles the reasons for use that developers gave in a large survey of AI programming assistants, in which reducing keystrokes and recalling syntax featured prominently {{liang2024survey}}, and it is consistent with use in what {{barke2023grounded|n}} call acceleration mode, where the engineer already knows what is needed and uses the tool to get there faster.

### 4.6.2 Perceived productivity and learning

Engineers on the whole believed that the assistant made them more productive, with a pooled mean of 3.8 (SD 0.9) on the five-point scale. The team means follow the same order as the repository results: Beacon highest at 4.2, Atlas at 3.7 and Cormorant lowest at 3.4. That the ordering of perceptions matches the ordering of measured throughput is a point of convergence between the two kinds of data. The match is not exact, however. Cormorant's mean of 3.4 lies on the agreeing side of the scale midpoint even though its throughput, cycle time and ITS estimate show no change, and Atlas's 3.7 indicates clearer perceived benefit than its imprecise throughput estimate can confirm. A gap of this kind between perceived and measured productivity has been reported before {{ziegler2022maps}}, and in Chapter 6 I consider whether it reflects benefits that the repository metrics do not capture, such as reduced effort on tedious tasks, or an overestimate of benefit.

The highest-rated statement in the survey concerned learning: respondents agreed that the assistant helped them learn unfamiliar APIs or languages, with a mean of 4.1 (SD 0.7), the lowest dispersion of any item. Use as a reference for unfamiliar material appears to be the least contested benefit of the tool among these engineers.

### 4.6.3 Review and trust

Three items concerned verification and trust, and together they describe engineers who were cautious about what the assistant produced. Respondents agreed that they reviewed assistant-generated code more carefully than code they had written themselves, with a mean of 3.9 (SD 0.8). They tended to disagree that they trusted its suggestions for security-sensitive code, with a mean of 2.3 (SD 1.0), the only item in the survey whose mean fell below the scale midpoint. This caution is consistent with the experimental literature reviewed in Chapter 2, which found that code produced with assistants can carry security weaknesses {{pearce2022asleep;perry2023insecure}}, although the survey cannot show whether the caution was well calibrated in practice.

The statement that reviewing teammates' PRs had become harder received a pooled mean of 3.4 (SD 1.1), the widest dispersion of any item, indicating that engineers were divided. The team means explain much of the division. Atlas respondents agreed most strongly, at 3.9, while Beacon (3.1) and Cormorant (3.2) were close to the midpoint. This aligns with the repository evidence of Section 4.3.2: Atlas showed the largest growth in both PR size and review comments, and its engineers were the most likely to report that review had become harder.

## 4.7 Summary

The quantitative strand gives a clear, if partial, answer to RQ1. The same assistant, introduced on the same day, was followed by different changes in the three teams. Beacon delivered more and faster: its throughput rose by an estimated 0.46 PRs per engineer-week at adoption, with a confidence interval that excludes zero, and its median cycle time fell by about a fifth, without a rise in reverts. Atlas changed shape more than speed: its throughput estimate was small and imprecise, but its PRs grew by about two-fifths, its review comments by about a quarter and its test ratio by about a quarter, and its security findings rose sharply for twelve weeks before falling below their earlier level after the team's agreement of 26 May 2025. Cormorant changed least on every metric, with no detectable change in throughput and only slight movements elsewhere.

The survey adds two elements to the answer to RQ2. Use was common and concentrated in routine work, especially boilerplate, tests and API lookup, with SQL writing distinctive to Cormorant. Engineers also described themselves as more careful with generated code and distrustful of it in security-sensitive areas, and those in Atlas were most likely to find review harder.

What the quantitative strand cannot do is explain these patterns. It does not show why Beacon's gains came as an immediate step, why Atlas's effort appears to have moved toward review, why Cormorant's heavy use for SQL left its metrics almost unchanged, or what lay behind the abrupt change in Atlas's security findings. Chapter 5 turns to the engineers' own accounts of their work to address these questions.
