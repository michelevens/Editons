# Chapter 6: Evaluation

This chapter reports the evaluation of the third-iteration Dose prototype described in Chapter 5. In the terms of the Design Science Research Methodology, it corresponds to the demonstration and evaluation activities {{peffers2007}}, and it closes the design cycle by testing the artifact against the requirements derived in Chapter 3 {{hevner2004}}. It answers RQ3: how usable and useful is the resulting prototype for community health workers in simulated field tasks? Sections 6.1 and 6.2 justify and describe the study; Section 6.3 reports task completion, time on task, the System Usability Scale and the usability problems found; Section 6.4 reports the supervisor walkthrough; Section 6.5 traces the twelve requirements (R1–R12) to the evidence; and Section 6.6 discusses what the results do and do not show.

## 6.1 Evaluation aims and strategy

The evaluation had three aims. The first was to establish whether CHWs who had not taken part in the requirements work could complete the core follow-up tasks with Dose, unaided, on the class of device they would use in Lowmoor District. The second was to identify usability problems severe enough to require redesign before any field pilot. The third was to check, requirement by requirement, whether the prototype embodied what Chapter 3 had specified, so that the design knowledge claimed in Chapter 7 rests on traceable evidence rather than on the designer's impression.

{{hevner2004|n}} allow several evaluation methods for design artifacts, from analytical arguments to experimental and field studies. A field deployment would have offered the strongest evidence of utility, but placing an untested tool into live patient follow-up was neither ethical nor feasible within a master's project. A comparison with the paper register was also rejected, as twelve participants would give too little power for a meaningful claim. I therefore chose a formative usability study in a simulated field day, combining task performance, think-aloud observation and a standardized satisfaction questionnaire, following the usability-testing guidance in {{lazar2017}}. This choice treats the evaluation as a further turn of the build-and-evaluate loop rather than as a final verdict, which is consistent with research through design {{zimmerman2007}}.

## 6.2 Method

### 6.2.1 Participants

Twelve CHWs (P01–P12) took part. None had been among the six CHWs shadowed during the requirements phase or the CHWs who tested the iteration 1 and 2 prototypes (Chapter 4); recruiting a fresh group avoided participants who had shaped the design, and who might therefore find it easier to use than a typical CHW would. Participants were recruited through the Lowmoor District CHW coordinator, who circulated an information sheet; the first twelve eligible volunteers were enrolled. Eligibility required at least six months of active follow-up work and daily use of an Android phone. Table 6.1 summarizes the group.

**Table 6.1** Usability study participant characteristics (n = 12)

| Characteristic | Value |
|---|---|
| Gender | 9 women, 3 men |
| Age, years | median 36 (range 24–51) |
| Experience as CHW, years | median 5 (range 1–14) |
| Patients on follow-up register | median 38 (range 21–64) |
| Phone ownership | 10 own an Android phone; 2 share a household phone |
| Preferred language for reading | 7 Kiswahili, 5 English |
| Prior use of any work app | 4 (data-collection forms) |

*Note.* Participant codes are used throughout; no names were recorded with the data.

The number of participants was set with the purpose of the study in mind. {{nielsen1993b|n}} model the discovery of usability problems as a process of diminishing returns, in which each additional participant uncovers a smaller share of new problems. For a formative study whose job is to find the problems that matter, around a dozen users of a homogeneous profile is a defensible size. Twelve participants cannot estimate population parameters precisely, which is why every proportion below is reported with an interval.

### 6.2.2 Setting and materials

The sessions took place in two community halls in Lowmoor District over four days, arranged as a "simulated field day". Each participant received a low-end Android test device with 1 GB of RAM, the same model used for the performance tests in Chapter 5, with Dose installed from the 11.8 MB APK and preloaded with a synthetic caseload of 40 patients. The caseload mixed hypertension, diabetes and TB continuation patients; on the day of the session, seven were overdue and five due today. Printed patient cards and "visit outcome" slips supplied the information a CHW would normally gather at a household.

The device began each session in airplane mode; for the sync task, the facilitator switched on a portable Wi-Fi hotspot connected to a test server running the synchronization API described in Chapter 5. Sessions were audio-recorded, screens were captured on the device, and a second researcher completed a structured observation sheet (Appendix B).

### 6.2.3 Tasks and measures

Five tasks were derived from the core scenarios in Chapter 3 and from the requirements they were meant to test (Appendix B gives the exact wording):

- **T1.** Find the patients who are overdue for follow-up (R2).
- **T2.** Record a home visit for a named patient while offline (R1, R3).
- **T3.** Reschedule a patient's next follow-up to a date given on a slip (R2).
- **T4.** Send the day's records to the server after a connection becomes available (R4).
- **T5.** Switch the interface language to the participant's other language and back (R5).

Three measures were collected. **Task completion** was binary: a task counted as complete if the participant reached the correct end state without facilitator assistance. **Time on task** was measured from the end of the facilitator's instruction to the participant's statement that they had finished, and was analyzed only for successful attempts. **Perceived usability** was measured with the System Usability Scale (SUS), administered after all tasks; its ten items, alternating positive and negative wording, are reproduced in Appendix B {{brooke2013}}. The questionnaire was offered in English or in a back-translated Kiswahili version prepared for the study; seven participants chose Kiswahili.

Usability problems were identified from the think-aloud recordings and observation notes. Each problem was rated on a four-point severity scale (cosmetic, minor, major, severe) based on its frequency, its impact on task success and whether users could recover without help, following the approach in {{nielsen1993}}.

### 6.2.4 Procedure

Each session lasted about 50 minutes. After consent, the facilitator gave a two-minute introduction to Dose, deliberately limited to opening the app and entering the PIN, so that the tasks would test discoverability rather than training. Participants were asked to think aloud and were prompted when silent. The facilitator did not help during tasks; if a participant was stuck for two minutes or asked to stop, the task was recorded as a failure. Tasks were presented in the fixed order T1–T5 because they formed a plausible working day, which counterbalancing would have broken. A short debrief interview followed the SUS.

### 6.2.5 Analysis

Completion rates were reported with adjusted-Wald 95% confidence intervals, which behave better than the simple Wald interval for small samples and for proportions close to 0 or 1 {{sauro2016}}. Times on task are reported as medians with interquartile ranges, because task times are positively skewed and the sample is small {{sauro2016}}. SUS scores were computed in the standard way: each odd-numbered item contributes its score minus one, each even-numbered item contributes five minus its score, and the sum is multiplied by 2.5, giving SUS = 2.5 × Σ(adjusted item scores) on a 0–100 scale {{brooke2013}}. The mean was interpreted against published benchmarks and adjective ratings {{bangor2008}}. Think-aloud data and debrief notes were coded for problems and for comments about usefulness, using a light, descriptive form of thematic analysis {{braun2006}}.

### 6.2.6 Ethics

The study received approval from the Kestrel Bay University School of Computing Research Ethics Committee and permission from the Lowmoor District health office. Participants were told that the study evaluated the app, not them, that taking part had no bearing on their employment, and that they could stop at any time. All patient data in the test caseload were synthetic. Participants received a modest transport allowance.

## 6.3 Results

### 6.3.1 Task completion

Table 6.2 reports completion rates. All twelve participants completed T1 and T5, and eleven completed T2. Completion was lower for T3 (9/12) and T4 (10/12).

**Table 6.2** Task completion with adjusted-Wald 95% confidence intervals (n = 12)

| Task | Completed | Rate | 95% CI |
|---|---|---|---|
| T1 Find overdue patients | 12/12 | 100% | 71.8%–100% |
| T2 Record a visit offline | 11/12 | 92% | 62.5%–100% |
| T3 Reschedule a follow-up | 9/12 | 75% | 46.1%–91.7% |
| T4 Sync after reconnecting | 10/12 | 83% | 54.0%–96.5% |
| T5 Switch language | 12/12 | 100% | 71.8%–100% |

*Note.* Upper limits above 100% are truncated to 100%.

The intervals are wide, as expected with twelve participants. What they support is a cautious ordering rather than precise estimates: even at the lower bound, T1 and T5 were completed by a clear majority of plausible users, whereas the lower bound for T3 falls below half. The single T2 failure (P07) was a slip rather than a breakdown in understanding: P07 opened a patient adjacent to the named one in the list, recorded the visit fully, and noticed the error only when the facilitator asked them to confirm. The three T3 failures and the two T4 failures were each traced to a specific design problem, discussed in Section 6.3.4.

### 6.3.2 Time on task

The median time to record a visit offline (T2) was 38 seconds (IQR 31–49 s) across the eleven successful attempts. This includes selecting the patient from the list, completing the four-field visit form and saving. Requirement R3 specifies at most three taps after selecting a patient; the screen recordings confirmed that all eleven successful attempts used the minimum three taps for a routine visit, with additional taps only where participants chose to add an optional note. Median times for the other tasks were 14 s for T1 (IQR 9–21 s), 71 s for T3 (IQR 52–96 s), 22 s for T4 (IQR 15–40 s) and 9 s for T5 (IQR 7–13 s). The T3 time is long for selecting a single date, and the recordings show most of it spent inside the date picker. Without a baseline, none of these times shows that Dose is faster than the paper register.

### 6.3.3 System Usability Scale

The mean SUS score was 78.3 (SD 9.6; median 78.75; range 65.0–95.0), with a 95% confidence interval of 72.2 to 84.4 using the t distribution with 11 degrees of freedom. Individual scores, in ascending order, were 65.0, 65.0, 67.5, 75.0, 77.5, 77.5, 80.0, 80.0, 80.0, 85.0, 92.5 and 95.0. The mean lies above the average of about 68 commonly used as a reference point for SUS {{sauro2016}}, and in the range that {{bangor2008|n}} associate with the adjective "good". The lower limit of the confidence interval also lies above 68, which suggests, with the caveats of a small and self-selected sample, that perceived usability was above average rather than merely average. Descriptively, the seven participants who completed the Kiswahili questionnaire scored lower (mean 76.4) than the five who completed the English one (mean 81.0), in the direction one would expect from the label truncation problem (Section 6.3.4); with groups this small, the difference could easily be chance.

### 6.3.4 Usability problems

Seventeen distinct usability problems were identified: three severe, five major, six minor and three cosmetic. Table 6.4 lists the severe and major problems; the full list, with the participants affected, is kept in the project's evaluation log.

**Table 6.4** Severe and major usability problems (8 of 17)

| ID | Problem | Severity | Participants affected | Linked task / requirement |
|---|---|---|---|---|
| UP1 | Reschedule date picker opens on current month with small day cells; participants scrolled past or mis-selected the target date | Severe | 7 (3 failed) | T3 / R2, R6 |
| UP2 | Sync badge's count of unsent records read as an error or alert count; participants tried to "clear" it or waited for it to disappear | Severe | 6 (2 failed) | T4 / R4 |
| UP3 | Kiswahili labels truncated on the visit form and filter tabs ("Waliochel…") | Severe | 5 | T2, T5 / R5 |
| UP4 | Overdue and due-today rows easily confused when screen brightness was low | Major | 4 | T1 / R2, R6 |
| UP5 | Patient list scroll position lost after saving a visit | Major | 5 | T2 / R3 |
| UP6 | Save confirmation naming the patient disappears too quickly to be read | Major | 3 (1 failed) | T2 / R3 |
| UP7 | "Reschedule" action hidden behind overflow menu on patient card | Major | 4 | T3 / R2 |
| UP8 | After sync, no indication that records were received by the server | Major | 3 | T4 / R4 |

The three severe problems share a pattern: each arose where a component was used outside the conditions it had been designed for. The date picker (UP1) replaced free-text date entry late in iteration 2 (Chapter 4), and the working prototype used the platform's default control, whose small day cells had never been tested with CHWs and fall short of the target-size guidance {{w3c2018}} that informed R6 elsewhere. The sync badge (UP2) matters most for the design argument of this thesis. The "quiet sync" decision replaced a blocking dialog with a small header badge, in line with the rule that systems should give informative feedback without interrupting {{shneiderman2016}}. The evaluation suggests the idea is sound but its signifier was wrong: a number on an icon carried the meaning "unread alerts" for participants used to phone notifications, a mapping problem in Norman's sense {{norman2013}}. The truncation problem (UP3) arose because Kiswahili strings are often longer than their English equivalents and the layout had been tuned to English.

### 6.3.5 Observations on usefulness

Three recurring debrief comments bear on usefulness. First, eight of the twelve participants named the due/overdue list as the most useful part of the app, often by contrast with paging through the register:

> With the book, I find the ones I missed only when I go back through the pages. Here they are waiting for me in the morning. (P04)

Second, participants trusted the offline behavior once they had seen it work, but not before. Several hesitated before saving the first visit in airplane mode, asking whether it would be "lost", which suggests that offline capability needs to be made visible, not only implemented:

> I thought it needs network to save. When it said saved, I checked again, and it was still there. (P09)

Third, comments on the sync badge confirmed UP2 and indicated a better signifier:

> The number on the cloud, I thought something is wrong with my phone. If it said "waiting to send" I would understand. (P11)

## 6.4 Supervisor walkthrough

The weekly summary (R8) could not be tested in a simulated CHW day, so I held a walkthrough with the two supervisors, Mrs. Adongo and Mr. Kiprop. Each used the summary generated from the twelve test sessions to answer three questions: which CHWs had the most overdue patients, how many visits had been recorded that week, and whether any patient had been overdue for more than two weeks.

Both answered the second and third questions easily and called the summary useful for weekly meetings. Neither could answer the first question directly, because the summary shows district totals only. Both asked for a per-CHW breakdown, and Mr. Kiprop noted that he would use it to decide whom to accompany on visits rather than to rank staff. This request is consistent with the supervisory practice observed in Chapter 3, and it explains why R8 is rated only as partially met below.

## 6.5 Requirements traceability

Table 6.5 traces each requirement to the evidence gathered in this chapter and in the technical tests of Chapter 5. A requirement is "met" if the evidence shows it embodied and working as specified, "partially met" if it is embodied but the evaluation exposed a gap in what the requirement was meant to achieve, and "not evaluated" if the available evidence cannot settle it.

**Table 6.5** Requirements traceability (R1–R12)

| Req. | Summary | Evidence | Status |
|---|---|---|---|
| R1 | Works fully offline | T1–T3 and T5 completed in airplane mode; no data loss across 12 sessions | Met |
| R2 | Due today / overdue list on open | T1 12/12; median 14 s; UP4 and UP7 noted | Met |
| R3 | Record a visit in ≤ 3 taps after selecting patient | Three taps in all 11 successful T2 attempts; median 38 s | Met |
| R4 | Sync when any connection appears, conflict-safe | T4 10/12; conflict tests passed (Ch5); conflict view designed but not built; UP2, UP8 | Partially met |
| R5 | Icons + short text; English/Kiswahili toggle | T5 12/12; UP3 truncation to fix | Met |
| R6 | Large touch targets and contrast | Main screens checked against WCAG 2.1 criteria; date picker excepted (UP1) | Met |
| R7 | Runs on 1 GB RAM Android; APK < 15 MB | APK 11.8 MB; cold start 2.9 s; 300-patient list in 410 ms (Ch5) | Met |
| R8 | Supervisor weekly summary | Walkthrough: useful, but lacks per-CHW breakdown | Partially met |
| R9 | PIN lock; patient data encrypted at rest | PIN entry used in all sessions; AES-GCM storage verified (Ch5) | Met |
| R10 | No SMS cost to CHW | No SMS used by design; sync over data only (Ch5) | Met |
| R11 | Paper register export for audit | Export generated and checked against register layout (Ch5) | Met |
| R12 | Battery-light (no constant GPS) | No location services used; no battery measurements taken | Not evaluated |

Nine requirements were met, two were partially met (R4 and R8), and one was not evaluated in the field (R12). For R4, the technical criterion of conflict safety was satisfied by the conflict tests in Chapter 5, but the requirement as elicited in Chapter 3 was also about CHWs being confident that their records had arrived; the absence of a delivery confirmation (UP8), together with the conflict view that was designed in Chapter 4 but not built, means that the second half was not achieved. R12 is recorded as not evaluated: the design avoids constant GPS, but no battery measurements were taken, and only field use could answer the question CHWs asked, which was whether the phone would last a working day.

## 6.6 Discussion

The evidence in this chapter answers RQ3 in a qualified way. Within a simulated field day, CHWs who had not seen Dose before could find overdue patients, record visits offline and switch language with high success, and they rated the prototype's usability as good, with a mean SUS of 78.3. Rescheduling and syncing were less successful, and in both cases the cause was a specific, fixable design problem rather than a mismatch between the tool and the work. Usefulness is supported more weakly: participants and supervisors described the list-first home screen and the weekly summary as useful, but those are statements of expected value, not observations of changed practice.

Several limitations bound these conclusions. The setting was simulated: printed cards in a hall, without the interruptions, sun glare and competing demands of household visits. Exposure was about 50 minutes, so the results say little about learning over weeks or about novelty effects. The fixed task order may have let later tasks benefit from practice. The Kiswahili SUS, though back-translated, has not been validated as equivalent. Volunteers may be more confident with phones than the CHW workforce as a whole. Finally, the evaluation measured nothing about patients: whether a tool like Dose reduces missed follow-ups, let alone improves adherence, cannot be inferred from a usability study, a gap that matters given how mixed the evidence for mHealth effects has been {{free2013}}.

Within those limits, the evaluation did what a formative evaluation should: it confirmed what works, located what does not, and gave each failure a fixable cause. The sync badge failure in particular sharpens the "quiet sync" principle, which Chapter 7 restates accordingly.

## 6.7 Summary

With twelve CHWs in a simulated field day, task completion ranged from 9/12 for rescheduling to 12/12 for finding overdue patients and switching language; recording a visit offline took a median of 38 seconds; and the mean SUS score was 78.3 (SD 9.6). Seventeen usability problems were found, three of them severe. Of the twelve requirements, nine were met, two were partially met and one could not be evaluated outside the laboratory. Chapter 7 reflects on what these results, together with the design process of Chapters 3 to 5, contribute, and on what should come next.
