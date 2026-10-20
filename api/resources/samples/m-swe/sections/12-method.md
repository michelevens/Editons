# Chapter 3: Research Design and Methods

## 3.1 Introduction

Chapter 2 concluded that the evidence on AI coding assistants is strong at two scales and weak at the one that matters most to engineering managers. Controlled experiments tell us how quickly an individual completes a bounded task with and without an assistant, and large telemetry studies tell us how often suggestions are accepted across many users. Neither tells us what happens to a working team, with its own code base, review culture and risk profile, once every engineer has the tool. This chapter explains how I designed a study to answer that team-level question at Lanternfish Systems, and why each design choice was made.

Section 3.2 sets out the stance and overall design; Section 3.3 describes the company, the teams and the intervention. Sections 3.4 and 3.5 cover the quantitative strand, including the interrupted time series model of Equation (3.1), and Sections 3.6 and 3.7 the survey and interviews. Section 3.8 explains integration, Section 3.9 assesses threats to validity and Section 3.10 sets out the ethical safeguards.

> **Sample note.** The data reported in this thesis are synthetic, created to illustrate how such a study is reported. No participants were recruited.

## 3.2 Research philosophy and design

### 3.2.1 A pragmatic stance

The three research questions differ in kind. RQ1 asks how measurable delivery and quality indicators changed, which calls for counting, comparison over time and an estimate of uncertainty. RQ2 asks how engineers used the assistant and how their practices of writing, reviewing and testing code changed, which calls for engineers' own accounts of work that leaves only partial traces in a repository. RQ3 asks which team-level conditions explain the differences between teams, which requires holding the quantitative pattern and the qualitative explanation side by side. No single method answers all three. I therefore adopted a pragmatic stance, in which the choice of method follows from the question rather than from a prior commitment to one epistemology. This position is common in empirical software engineering, where {{easterbrook2008selecting|n}} argue that the researcher's stance should be made explicit precisely because it shapes what counts as an acceptable answer.

### 3.2.2 Convergent mixed methods

Within that stance, I used a convergent mixed-methods design {{creswell2018mixed}}. In a convergent design the quantitative and qualitative strands are collected in roughly the same period, analyzed separately, and then brought together to see where they agree, where they diverge and what each adds to the other. I considered and rejected an explanatory sequential design, in which interviews would be selected after the quantitative analysis to explain surprising results. The company's calendar allowed only one interview window, shortly after the observation period closed, and a sequential design would have tied the interview sample to whatever anomalies the metrics happened to show. I wanted engineers' accounts to stand as evidence in their own right, capable of contradicting the metrics rather than only explaining them.

The two strands were weighted equally. The quantitative strand addresses RQ1 directly and contributes to RQ3; the qualitative strand addresses RQ2 directly and contributes to RQ3. RQ3 is answered only at the point of integration (Section 3.8 and Chapter 6).

### 3.2.3 An embedded multiple-case study

The mixed-methods design sits inside a case study. Following {{runeson2009case|n}}, I treat a case study as an empirical inquiry into a contemporary phenomenon in its real-life context, where the boundary between the phenomenon and the context is not clear and the researcher has no control over events. The adoption of the assistant at Lanternfish was exactly such a phenomenon: the company chose the date, the tool and the rollout, and I observed what followed. The design is a multiple-case study with embedded units of analysis {{runeson2009case;easterbrook2008selecting}}. The cases are the three teams; the embedded units are the pull requests (for the repository strand) and the engineers (for the survey and interview strand). Selecting three contrasting teams within one company holds constant the tool, the rollout date, the review platform, the static analysis configuration and the wider engineering culture. What varies between the teams is the kind of code they write and the cost of getting it wrong, which is the variation RQ3 is designed to explain.

The case study logic also shapes what may be claimed. The aim is analytical rather than statistical generalization: the study seeks to build and test a theoretical explanation of why the same tool produced different effects in different teams, not to estimate an average effect for the software industry.

### 3.2.4 Reporting standard

To make the study assessable, I reported it against the ACM SIGSOFT Empirical Standards {{ralph2020standards}}, which express the community's expectations for particular kinds of study as checklists of essential and desirable attributes. The relevant standards are those for case studies, questionnaire surveys, repository mining and mixed methods. Where a standard's essential attribute could not be met, the gap is acknowledged in Section 3.9 rather than left implicit.

## 3.3 The case and its units

### 3.3.1 Lanternfish Systems

Lanternfish Systems is a business-to-business software-as-a-service company that builds scheduling and payroll software for clinics. At the time of the study it employed 140 engineers organized into product-aligned teams, each owning a set of services or applications and responsible for reviewing and deploying its own changes. All teams used a single hosted version control and code review platform, a shared continuous integration pipeline, and a common static application security testing (SAST) configuration maintained by a central platform group. Every change to a production branch required at least one approving review from a team member other than the author.

Access was negotiated through the company's VP of Engineering, who acted as industry contact and gatekeeper. The company is not named in this thesis, and the name Lanternfish Systems is a pseudonym (Section 3.10).

### 3.3.2 The intervention

On 3 March 2025 Lanternfish rolled out a commercial AI coding assistant to all engineers. The assistant offered two modes of interaction inside the integrated development environment (IDE): inline code completion, which proposes the continuation of the line or block being typed, and a chat panel in which engineers could ask questions, request code or ask for explanations of selected code. In this thesis it is referred to simply as "the assistant"; the specific product is not identified, both to protect the company's identity and because the study's interest lies in team adoption rather than in the merits of one vendor. The rollout was simultaneous for all engineers, accompanied by a one-hour introductory session and a short internal guidance page that asked engineers not to paste customer data into the chat panel. No other usage rules were set centrally. This matters for the analysis: any team-level norms that emerged later, such as the agreement adopted by the Atlas team in week 12 of the after period, were the teams' own.

The adoption date defines the two observation windows used throughout the thesis. The **before** period covers the 26 weeks from 2 September 2024 to 2 March 2025, and the **after** period covers the 26 weeks from 3 March to 31 August 2025. Weeks in the after period are numbered 1 to 26 from the adoption date.

### 3.3.3 Selection of the case teams

The three teams were selected purposively, using a logic of theoretical replication: they were chosen because they were expected to differ on the conditions that the literature reviewed in Chapter 2 suggests should matter, namely how easily generated code can be checked and how costly an undetected error would be. With the VP of Engineering I reviewed the team list against these two dimensions and against two practical criteria: the team had to have existed in a stable form for the full 52 weeks, and its lead had to agree to participate. Table 3.1 summarizes the three teams.

**Table 3.1** The three case teams (size, stack, domain, stakes).

| Team | Engineers | Main stack | Domain | Stakes of an undetected error |
|---|---|---|---|---|
| Atlas | 16 | Kotlin and Java services | Payments and payroll backend | High: regulated code; errors affect money owed to clinic staff and personal data |
| Beacon | 15 | TypeScript and React | Customer-facing web front end | Moderate: errors are usually visible in the interface and quickly reverted |
| Cormorant | 13 | Python and SQL | Data platform and reporting pipelines | Moderate to high: errors may silently corrupt reports that clinics rely on |

*Note.* Team names are pseudonyms. Engineer counts are the stable team membership across the 52-week window; the three teams together comprise 44 engineers.

The contrast is deliberate. Beacon's front-end code is, in a practical sense, easy to verify: a wrong component renders wrongly and a faulty release is cheap to undo. Atlas's code is harder to verify and expensive to get wrong, because a subtle error in a payroll calculation or an authorization check may pass every visible test. Cormorant's pipelines are tested, but correctness depends on the semantics of data, so a query can run cleanly and still be wrong. All three teams began the window with the same company-wide review rules and had a tech lead in post throughout.

## 3.4 Strand 1: Repository mining

### 3.4.1 Data source and extraction

The quantitative strand drew on the code review platform's records of pull requests (PRs) for the repositories owned by the three teams. A PR was attributed to a team if its author was a member of that team on the date it was opened; PRs authored by people outside the three teams, including the platform group's automated dependency updates, were excluded. For each PR I extracted, through the platform's application programming interface, the timestamps of opening and merging, the files changed with line additions and deletions, the review comments, and any later commit that reverted it. SAST findings were extracted from the continuous integration pipeline's results for each merged PR. The extraction scripts were run by a Lanternfish engineer inside the company's network, under my direction, and returned only the derived, pseudonymized records described in Section 3.10. The definitions and an example extraction query are given in Appendix C.

### 3.4.2 Metric definitions

Seven metrics were defined in advance of the extraction. They were chosen to cover several dimensions of productivity rather than throughput alone, following the argument of {{forsgren2021space|n}} that no single measure captures developer productivity and that activity counts are especially misleading when read in isolation. Two of the metrics, cycle time and the revert rate, are close relatives of the software delivery measures described by {{forsgren2018accelerate|n}}: cycle time is a narrow, code-review-level analogue of lead time for changes, and the revert rate is a proxy for change failure. Review comments were included because modern code review serves purposes beyond defect detection, including knowledge transfer and shared understanding of a change {{bacchelli2013review;sadowski2018google}}, and a change in the volume of review discussion is therefore informative about collaboration as well as about quality. Table 3.2 gives the definitions and maps each metric to the SPACE dimension it most closely represents.

**Table 3.2** Repository metrics and their definitions.

| Metric | Definition | Unit | SPACE dimension |
|---|---|---|---|
| PRs merged per engineer-week | Number of PRs merged in a week divided by the number of team engineers active that week | PRs per engineer-week | Activity |
| Median PR cycle time | Median elapsed time from PR opening to merge, for PRs merged in the period | Hours | Efficiency and flow |
| Median changed lines per PR | Median of lines added plus lines deleted, excluding generated files, lock files and vendored code | Lines | Activity |
| Mean review comments per PR | Mean number of human-authored review comments per merged PR, excluding bot comments | Comments | Communication and collaboration |
| Security findings per 1,000 changed lines | SAST findings of medium severity or above introduced by merged PRs, divided by changed lines and multiplied by 1,000 | Findings per 1,000 lines | Performance |
| PRs reverted within 14 days | Percentage of merged PRs reverted, wholly or partly, by a commit merged within 14 days | Percent | Performance |
| Test lines changed per 100 production lines | Changed lines in test files divided by changed lines in non-test source files, multiplied by 100 | Lines | Performance (process) |

*Note.* Test files were identified by each repository's naming and directory conventions, confirmed with each team's tech lead. Full extraction logic is in Appendix C.

Several definitional choices deserve justification. Throughput was normalized by engineer-weeks so that holidays and leave would not appear as changes in productivity. Medians were used for cycle time and PR size because both distributions are heavily right-skewed; for review comments, which are less skewed, the mean was retained so that a general increase in discussion would not be hidden by a median stuck at a small integer. Security findings were normalized by changed lines because a team that writes more code will, other things equal, introduce more findings; the question of interest is whether code became more or less risky per unit written. Because this normalized rate is computed on lines pooled over a period, it weights periods with more changed code more heavily than a simple average of two-week rates would. Finally, the 14-day revert window was chosen with the three tech leads as the period within which a revert almost always signals a defect in the reverted change rather than a later change of direction.

### 3.4.3 What the metrics cannot show

Individual-level usage telemetry from the assistant was not available to the study. The company had decided before the rollout not to collect per-engineer acceptance data, and I did not ask it to change that decision. The consequence is that the repository strand cannot distinguish PRs written with heavy assistant use from PRs written without it. The treatment in the quantitative analysis is therefore team exposure to the assistant from 3 March 2025, not individual use. This is a limitation for estimating an effect per accepted suggestion, as earlier telemetry studies have done {{ziegler2022maps}}, but it is the appropriate unit for a team-level question, and the survey and interviews supply the missing account of how intensively and for what the assistant was used.

## 3.5 Interrupted time series analysis

A simple comparison of before and after means, as in Table 4.1, cannot separate a change caused by the adoption from a trend already under way. If a team's throughput was rising through the autumn of 2024, a higher mean after March 2025 would be expected even without the assistant. I therefore modeled the weekly throughput series of each team as an interrupted time series (ITS), a quasi-experimental design suited to situations in which an intervention occurs at a known point and outcomes are observed repeatedly before and after it {{wohlin2012experimentation}}. For each team the model was

$$
y_t = \beta_0 + \beta_1 t + \beta_2 D_t + \beta_3 (t - T_0) D_t + \varepsilon_t \tag{?}
$$

where $y_t$ is PRs merged per engineer-week in week $t$, with $t$ running from 1 to 52; $T_0$ is the first week of the after period (the week beginning 3 March 2025); $D_t$ is an indicator equal to 0 before $T_0$ and 1 from $T_0$ onward; and $\varepsilon_t$ is the error term. In Equation (3.1), $\beta_1$ is the pre-existing weekly trend, $\beta_2$ is the immediate change in level at adoption, and $\beta_3$ is the change in the weekly trend after adoption. The quantity of most interest for RQ1 is $\beta_2$, the level change, read together with $\beta_3$ to establish whether any change was a step, a gradual drift or neither.

Weekly delivery data are typically autocorrelated, which leaves ordinary least squares coefficients unbiased but their standard errors too small. I therefore estimated standard errors with the Newey–West heteroskedasticity- and autocorrelation-consistent estimator with a lag of two weeks [source needed], chosen after inspecting the autocorrelation function of the residuals of each team's model, which showed no material autocorrelation beyond the second lag. Results are reported as point estimates with 95% confidence intervals.

The ITS model was fitted to throughput only. The other six metrics were reported descriptively, as before and after values, for two reasons. First, they are defined at the level of the PR, and their weekly values are noisy for teams of 13 to 16 engineers, especially for rare events such as reverts. Second, fitting seven models to each of three teams would have multiplied the number of tests well beyond what the data could support. The security metric for the Atlas team was additionally examined as a series of two-week periods, because the team changed its practice part-way through the after period and a single after-period value would hide that change (Section 4.5).

## 3.6 Strand 2: Survey

### 3.6.1 Instrument

The survey was designed to describe how widely and for what purposes the assistant was used, and to measure engineers' perceptions of its effects on productivity, review, trust and learning. It consisted of a usage frequency item, a multi-select item on purposes of use, a set of statements rated on a five-point agreement scale from 1 (strongly disagree) to 5 (strongly agree), and open-text questions inviting examples. Items were written for this study, informed by the dimensions of the SPACE framework {{forsgren2021space}} and by the concerns about review and security raised in the literature reviewed in Chapter 2. The full instrument is given in Appendix A. Before the survey was opened, the items were piloted with a small number of engineers from a team outside the study, which led to the rewording of statements that the pilot respondents found ambiguous.

Following the questionnaire survey standard {{ralph2020standards}}, I specified in advance which comparisons would be made. Three measures were designated for comparison between teams because they bear directly on RQ3: daily use, perceived productivity, and the perceived difficulty of reviewing teammates' PRs. All other items were planned for pooled reporting only, since subsamples of 11 to 14 respondents per team cannot support stable item-by-item comparisons, and making many unplanned comparisons would invite spurious findings.

### 3.6.2 Administration and response

The survey was administered online in September 2025, after the after period had closed, to all 44 engineers in the three teams. It was anonymous: respondents indicated their team and a seniority band but no other identifying information, and the company received only aggregated results. Two reminders were sent. Thirty-eight engineers responded, a response rate of 86%: 14 of 16 in Atlas, 13 of 15 in Beacon and 11 of 13 in Cormorant.

### 3.6.3 Analysis

Agreement items were summarized as means and standard deviations; although treating Likert-type responses as interval data is debated, I checked that medians told the same story for every item reported. Frequencies are reported as counts and percentages of respondents. Given the sample sizes, survey results are treated descriptively and no inferential tests between teams are reported.

## 3.7 Strand 2: Interviews

### 3.7.1 Sampling

Eighteen semi-structured interviews were conducted, six per team. Within each team, interviewees were selected purposively to span seniority, so that each team's sample included its tech lead, at least one junior engineer and engineers at mid, senior or staff level. Survey respondents could volunteer for interview through a separate form not linked to their survey answers; where volunteers did not cover a seniority band, I asked the tech lead to pass an invitation to engineers in that band, without telling the lead who accepted. Equal numbers per team were chosen so that no team's account would dominate the cross-case comparison.

Eighteen interviews is a modest number, but sufficiency in reflexive thematic analysis is judged by whether the material supports a rich, coherent interpretation rather than by a count of new codes.

### 3.7.2 Interview guide and conduct

The interview guide (Appendix B) was organized around three blocks that mirror RQ2 and RQ3: how the interviewee used the assistant in a typical week; how writing, reviewing and testing had changed, for themselves and for the team; and what the team had agreed, explicitly or tacitly, about its use. Interviewees were repeatedly asked for concrete episodes, such as a specific PR or review, because general opinions about AI tools are easy to elicit and of limited value.

Interviews were conducted by video call between September and October 2025 and each lasted under an hour. With consent, they were recorded and transcribed, and transcripts were checked against the recordings before the recordings were deleted. Each interviewee is identified in this thesis by a pseudonym, team and seniority, for example "Priya, Atlas, senior".

### 3.7.3 Reflexive thematic analysis

The interview transcripts and the survey's open-text answers were analyzed using reflexive thematic analysis {{braun2006thematic}}. I followed the six phases the method describes: familiarization with the data, generation of initial codes, construction of candidate themes, review of themes against the coded extracts and the whole data set, definition and naming of themes, and writing up. Coding was inductive in the first instance, staying close to engineers' own language, and was then brought into dialogue with the concepts from Chapter 2, such as the distinction between acceleration and exploration in assistant use and the role of review in knowledge transfer.

In keeping with the reflexive approach, themes were treated as patterns of shared meaning that I constructed from the data, not as objects that emerged unaided. I kept an analytic journal recording the decisions that shaped each theme and my own position as a former software engineer who had used AI assistants. I did not compute inter-rater agreement, which fits a coding reliability approach with codes fixed in advance; instead, my supervisor reviewed a sample of coded transcripts and the evolving thematic map, and our disagreements were used to sharpen the definitions of themes. The analysis produced five themes, reported in Chapter 5.

## 3.8 Integration

Integration happened at two points. At the design stage, the two strands were aligned so that they could speak to each other: the survey asked about the same activities that the repository metrics measured, and the interview guide asked about writing, reviewing and testing, the practices that underlie PR size, review comments and the test ratio. At the interpretation stage, the results of the two strands were brought together in a joint display {{creswell2018mixed}}, a table that sets, for each team and each area of practice, the quantitative result beside the relevant qualitative theme and states the meta-inference drawn from the pair. The joint display is presented as Table 6.1 in Chapter 6.

For each pair I recorded whether the strands converged (both point the same way), complemented each other (one explains or extends the other), or diverged (they appear to conflict). Divergence was treated as analytically valuable rather than as an error: where engineers reported working faster but the repository showed no change in throughput, for example, the conflict prompted a closer look at what the metric did and did not capture. The cross-case comparison that answers RQ3 was built from these meta-inferences, by asking which team-level conditions consistently accompanied the patterns observed in each team.

## 3.9 Threats to validity

I assess validity using the four classes common in experimental software engineering, namely construct, internal, external and conclusion validity {{wohlin2012experimentation}}, supplemented by the reliability criterion that {{runeson2009case|n}} add for case studies.

### 3.9.1 Construct validity

The central construct threat is that the repository metrics are proxies. PRs per engineer-week measures activity, not value; a team that splits work into smaller PRs appears more productive without delivering more. I mitigated this by reading throughput alongside PR size and cycle time, and by refusing to treat any single metric as "productivity", in line with the SPACE argument {{forsgren2021space}}. SAST findings measure what the analyzer's rules detect, not security as such, and the rule set may under-detect the logic errors that matter most in payroll code. The revert rate captures only failures serious enough to be undone within 14 days. Each of these limits is carried into the interpretation in Chapter 6. A further construct threat concerns the survey: perceived productivity may track the pleasantness of a tool more than its effect, a gap between perception and measurement that earlier work also observed {{ziegler2022maps}}.

### 3.9.2 Internal validity

The ITS design controls for pre-existing linear trends but not for other events coinciding with the adoption. I asked each tech lead to list changes in team membership, process, tooling and product priorities during the 52 weeks. None reported a reorganization, a company-level change of review rules or a change to the SAST configuration; the norms the teams set for themselves after adoption are findings of this study (Chapter 5), not confounders to be removed. Even so, ordinary shifts in product priorities cannot be ruled out as contributors to any team's change in throughput. The design's main weakness is the absence of a non-adopting comparison group: because every engineer received the assistant on the same day, no team could serve as a control. Seasonal effects, notably the December holidays, are reduced but not removed by normalizing by engineer-weeks, and the 26-week after window was chosen partly so that a novelty effect in the first weeks would not dominate the results.

### 3.9.3 External validity

The findings come from three teams in one mid-sized company in one domain using one assistant. They do not support statistical generalization to the industry. The case study logic aims instead at analytical generalization {{runeson2009case}}: the model developed in Chapter 6 is offered as a set of conditions whose influence can be tested in other settings, and the descriptions of the teams in Table 3.1 are intended to let readers judge how far their own context resembles these cases.

### 3.9.4 Conclusion validity

With 52 weekly observations per team, the ITS estimates have limited precision, and small changes in level cannot be distinguished from zero. I report confidence intervals rather than significance verdicts so that readers can see the range of effects compatible with the data. The survey subsamples are small, which is why between-team comparisons were restricted to three planned measures and reported descriptively.

### 3.9.5 Reliability

To support reliability, the metric definitions were fixed before extraction and are documented with an extraction query in Appendix C, the survey and interview guide are reproduced in Appendices A and B, and the analytic journal records how themes were developed. A researcher with equivalent access should be able to repeat the quantitative strand exactly and follow the reasoning of the qualitative strand.

## 3.10 Ethics

The study was approved by the Kestrel Bay University research ethics committee before any data were collected, and was conducted under a data access agreement between the university and the company. Four safeguards were central.

First, **informed consent**. Every participant received an information sheet explaining the study, how data would be stored and reported, and their right to withdraw. Interviewees gave written consent and could withdraw up to the point of analysis, and neither the company nor team leads learned who had taken part.

Second, **protection of individuals in an employment setting**. Because the study took place inside a company, there was a risk that research data could be used to judge individual performance. To prevent this, repository data were aggregated to team-week level before they left the company's network, author identifiers were replaced by salted hashes inside the network, and no individual-level metric was computed or reported. The survey was anonymous, and survey results were shared with the company only in aggregate. Interview quotations are attributed by pseudonym, and details that could identify a speaker to colleagues were removed or altered.

Third, **company anonymity**. The company is referred to by the pseudonym Lanternfish Systems, and the teams by pseudonyms. The assistant is not named, the description of the product domain is kept general, and no code, repository names or customer information appear in the thesis. The VP of Engineering reviewed a draft of the thesis for commercially sensitive information only; the company had no right to alter findings or interpretation.

Fourth, **data handling**. Data were held on encrypted university storage, recordings were deleted after transcription, and no customer data were accessed at any stage.

## 3.11 Summary

This chapter has described a convergent mixed-methods study embedded in a multiple-case study of three contrasting teams: seven repository metrics over 26 weeks either side of adoption, analyzed with the model of Equation (3.1), alongside a survey of 38 engineers and 18 interviews, integrated in a joint display. The main threats to validity, above all the lack of a non-adopting control group and the proxy nature of the metrics, have been stated so that the results can be read with appropriate caution. Chapter 4 now reports the results of the quantitative strand: the repository metrics, the time series estimates and the survey.
