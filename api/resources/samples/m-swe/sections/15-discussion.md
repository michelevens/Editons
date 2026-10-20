# Chapter 6: Discussion

This chapter integrates the two strands of the study and interprets the result. Section 6.1 brings the repository and survey findings of Chapter 4 together with the themes of Chapter 5 in a joint display, as the convergent design requires {{creswell2018mixed}}. Section 6.2 answers the three research questions. Section 6.3 proposes a team-level model of adoption, and Section 6.4 compares the findings with the published literature. Sections 6.5 and 6.6 set out the implications and the threats to validity.

## 6.1 Integrating the strands

Table 6.1 is the joint display. Each row pairs a quantitative result with the qualitative theme that bears on it and states the meta-inference drawn from the two together. Following the procedure set out in Section 3.8, each pair is labeled as convergence where both strands point the same way, complementarity where one explains or extends the other, and divergence where they appear to conflict.

**Table 6.1** Joint display: quantitative results, qualitative themes and meta-inferences.

| Quantitative result | Qualitative theme | Fit | Meta-inference |
|---|---|---|---|
| Beacon throughput rose (4.2 to 4.7 PRs per engineer-week; level change 0.46, 95% CI 0.21 to 0.71) and cycle time fell (19.8 to 15.6 h) | Theme 1: acceleration in patterned code; Theme 3: errors cheap and visible | Convergence | Gains appeared where generated code could be verified by running it |
| Cormorant throughput unchanged (2.4 to 2.4; level change 0.02); cycle time 31.2 to 32.0 h; productivity agreement 3.4 | Theme 1: "the time saved was eaten by checking"; Theme 5: SQL review rule | Complementarity | Frequent use did not translate into throughput because verification of data semantics set the pace |
| Atlas PR size rose 118 to 164 lines; review comments 4.6 to 5.8; "reviewing has become harder" 3.9 | Theme 2: reviewing a stranger's code | Convergence and complementarity | Effort moved from writing to reviewing; throughput gain was small and uncertain (0.18, CI −0.05 to 0.41) |
| Atlas security findings 0.82 before, 1.14 in weeks 1–12, 0.71 in weeks 13–26 | Theme 3: the security episode; Theme 5: week-12 agreement | Complementarity | The rise was temporary and coincided with the absence of explicit norms; the fall coincided with their adoption |
| Atlas test ratio 41 to 52 per 100 production lines | Theme 1: tests cheaper; Theme 5: mandatory tests | Complementarity | Two mechanisms, cost and obligation, operated together |
| Revert rates near-stable in all teams (largest change 1.9 to 2.2 in Atlas) | Theme 3: calibrated trust; pipeline scanning | Convergence | No evidence of a surge in shipped defects in this window |
| Trust for security-sensitive code 2.3; careful review of generated code 3.9 | Theme 3: trust scales with stakes | Convergence | Engineers reported calibration, though they described it as eroded by time pressure |
| Help learning unfamiliar APIs 4.1; daily use lowest in Cormorant (7/11) | Theme 4: who learns what | Partial divergence | Highly valued learning aid, but juniors questioned its effect on skill formation, which the metrics cannot capture |

*Note.* Quantitative values are from Tables 4.1–4.3 and Figure 4.2. Themes are from Table 5.1.

Three features of the display deserve comment. First, most rows show convergence or complementarity, which strengthens confidence that the repository metrics were measuring something engineers recognized. Second, complementarity was more common than simple convergence. The repository data repeatedly showed *that* something had changed, or had failed to change, while the interviews supplied the mechanism: verification moving to reviewers in Atlas, checking absorbing the time saved in Cormorant, and two distinct reasons for Atlas's rising test ratio. A purely quantitative study would have recorded the Cormorant result as a null effect and stopped there; the interviews show that engineers were using the tool frequently and that its gains were being spent on verification rather than on throughput. Third, the one partial divergence concerns learning, which is exactly the dimension that delivery metrics are least able to see. This is not a failure of integration but an instance of what integration is for: it marks the edge of what the quantitative strand could establish.

The display also clarifies one apparent puzzle. Security findings per 1,000 changed lines rose in all three teams over the full after period (Table 4.1), yet only Atlas engineers described a security problem. The increases in Beacon (0.55 to 0.61) and Cormorant (0.40 to 0.43) were small, did not prompt any discussion within those teams, and were not mentioned in their interviews. The Atlas increase was not only larger but concentrated in the first twelve weeks, which made it visible as an event rather than as drift. Whether the smaller rises in Beacon and Cormorant reflect a lasting effect of generated code on the rate of detected weaknesses cannot be settled with 26 weeks of data, and it is noted here as an unresolved question rather than a finding.

## 6.2 Answering the research questions

**RQ1** asked how delivery and quality indicators changed in each team in the 26 weeks after adoption. The answer differs by team. Beacon showed a clear increase in throughput, with a level change of 0.46 pull requests per engineer-week whose confidence interval excluded zero, and a reduction of about a fifth in median cycle time. Atlas showed a small throughput increase whose interval included zero, larger pull requests, more review comments and a higher test ratio. Cormorant showed essentially no change. No team showed a slope change of practical size (|β3| < 0.01 in Equation (3.1)), which suggests a one-off shift in level rather than a continuing acceleration. Quality indicators moved modestly. Security findings rose slightly in all three teams over the full period, with the Atlas pattern dominated by a transient rise and subsequent fall, and revert rates were near-stable.

**RQ2** asked how engineers used the assistant and how their practices changed. The answer is that use concentrated on routine code, tests and unfamiliar APIs; that authorship thinned as acceptance replaced writing; and that verification shifted toward reviewers unless a norm pushed it back to authors. Engineers calibrated trust to stakes, and seniority shaped whether the assistant was experienced as a reference or as a substitute for knowledge not yet acquired.

**RQ3** asked which team-level conditions explain the differences. The combined evidence points to three conditions: how easily a team's code could be verified by executing it, how costly an undetected error would be, and whether the team had made explicit agreements about the assistant's use. These are developed in the next section.

## 6.3 A team-level adoption model

The central argument of this thesis is that the assistant's effect was a property of the team rather than of the tool. All three teams used the same product, configured the same way, within the same company, over the same weeks. The variation in outcomes therefore has to be explained by something the teams brought to it. Figure 6.1 summarizes the model the evidence suggests.

> **Figure 6.1** The verifiability × stakes × norms model. Two task conditions, *verifiability* (how quickly and cheaply an error in generated code is revealed by running it) and *stakes* (the cost of an error that escapes), jointly determine where verification effort falls when an assistant is introduced. High verifiability and moderate stakes (Beacon) → errors surface at the author's desk → throughput gains with little added review. Verifiability only against data, with moderate-to-high stakes (Cormorant) → verification dominates and is unchanged by the tool → little measurable change. Low verifiability by execution and high stakes (Atlas) → verification migrates to the reviewer, who must check by reading → larger PRs, more review, transient quality risk. A third element, *explicit norms*, acts as a moderator on every path: it relocates verification (Beacon's explain-it-to-review rule returns it to the author; Atlas's agreement flags and channels it; Cormorant's SQL rule fixes it at a gate). Outcomes are read across SPACE dimensions rather than throughput alone.

Three features of the model should be stressed. First, verifiability and stakes are properties of a team's work, not of its engineers. Beacon's engineers were not more skilled or more enthusiastic than Cormorant's; their errors were simply cheaper to find. Second, the model treats norms as a moderator rather than a third independent cause. In each team, the norm that emerged fitted the team's position on the other two dimensions, which is why participants described them as codifications of existing practice (Section 5.6.4). Third, the model predicts where effort goes, not only how much output there is. That is why it must be read against a multidimensional view of productivity.

The SPACE framework argues that productivity cannot be captured by a single metric and should be read across satisfaction, performance, activity, communication and collaboration, and efficiency {{forsgren2021space}}. Read through that lens, Atlas's experience looks less like a modest success and more like a redistribution. Activity rose slightly; communication and collaboration, as measured by review comments, rose more; and performance, in the sense of quality outcomes, first dipped and then recovered. Throughput alone would have described Atlas as a team where little happened. The delivery measures popularized by {{forsgren2018accelerate|n}} were useful here as a reminder that cycle time and change failure, not volume, are the outcomes that matter to an organization; on those measures Atlas improved slightly and did not deteriorate.

## 6.4 Comparison with the literature

### 6.4.1 Productivity evidence

The controlled experiment reported by {{peng2023copilot|n}}, in which developers with an assistant completed a well-specified JavaScript task substantially faster, is often cited as evidence of a general productivity effect. The present findings suggest a qualification. Beacon's setting, a familiar language and patterned code whose correctness is quickly visible, resembles the experimental task more closely than Atlas's or Cormorant's work does, and Beacon was the only team with a clear throughput effect. Benchmark studies of code models similarly evaluate functional correctness on self-contained problems {{chen2021codex;austin2021mbpp;li2022alphacode}}, which are by construction highly verifiable. Field studies of perceived productivity found that acceptance of suggestions drove developers' sense of being productive {{ziegler2022maps;ziegler2024cacm}}. The survey results here are consistent with that perception being real but uneven: agreement was highest in Beacon and lowest in Cormorant, mirroring the repository data.

### 6.4.2 Interaction with the assistant

The first theme corresponds closely to the acceleration mode identified by {{barke2023grounded|n}}, in which programmers who know what they want use the assistant to get there faster, and to the survey finding of {{liang2024survey|n}} that developers use assistants chiefly to save keystrokes and recall syntax, and avoid them when output misses requirements. Exploration mode was less visible in this study, but the use of chat to learn unfamiliar APIs (Theme 4) resembles it. The time costs of verifying and editing suggestions documented by {{mozannar2024cups|n}} offer a mechanism for Cormorant's finding that time saved was "eaten by checking". Similarly, the observation of {{vaithilingam2022expectation|n}} that participants valued generated code as a starting point while finding it hard to understand and debug anticipates Sofia's description of an afternoon to a working client followed by two days of making it correct.

### 6.4.3 Security

Laboratory and benchmark studies have found that assistant-generated code is frequently vulnerable in security-relevant scenarios {{pearce2022asleep}}, and that users with an assistant may write less secure code while believing it more secure {{perry2023insecure}}. A user study in C, in contrast, found only a small difference in critical bugs {{sandoval2023lostatc}}. The Atlas episode is consistent with both lines of evidence in different ways. The early rise in findings resembles the laboratory concern, and Leon's undetected token logging illustrates the gap between confidence and security. Yet the effect was modest, caught by the pipeline, and reversed once the team adopted norms. In this case the organizational context, scanning plus explicit rules, appears to have limited the risk that controlled studies identify. That is a hypothesis for further study rather than a general claim.

### 6.4.4 Code review

Studies of modern code review have shown that, although defect-finding is its stated purpose, much of its value lies in knowledge transfer and team awareness, and that understanding the change is the principal difficulty for reviewers {{bacchelli2013review}}. Work at large scale has shown how review practice is shaped by local norms and tooling {{sadowski2018google}}. Theme 2 suggests that an assistant can intensify the understanding problem: when authors accept code they have not fully read, the reviewer inherits the burden of comprehension without the author's explanation. Beacon's rule can be read as an attempt to restore the explanatory dialogue that review depends on. Moradi Dakhel and colleagues found that assistant-generated buggy solutions were often easier to repair than incorrect human ones {{dakhel2023asset}}; the present data add that the cost of finding the bug in the first place may fall on someone other than the author.

### 6.4.5 Learning

Ross and colleagues found that participants in a conversational assistant study came to value its breadth and responsiveness despite initial skepticism {{ross2023assistant}}. The present study found similar appreciation for chat as a reference, especially among senior engineers. The juniors' concern about skill formation, however, has no measurable counterpart in this study's data, and the literature reviewed in Chapter 2 offers little direct evidence on it. It is flagged here as an open question.

## 6.5 Implications

### 6.5.1 For practice

Three implications for engineering organizations follow, each calibrated to a single company and three teams. First, organizations should evaluate an assistant's effect team by team, and should expect it to be largest where code is easily verified. Company-wide averages would have hidden both Beacon's gain and Atlas's redistribution. Second, teams should make their norms explicit early. The interviews suggest that norms were present tacitly from the start, but that their codification was prompted by a problem; Atlas's agreement came after twelve weeks of elevated findings. A lightweight agreement at adoption, covering how generated code is labeled, tested and explained, could have shortened that window. Third, review capacity should be treated as a constraint. If authorship thins, review must absorb the difference, and teams that track only throughput will not see the cost.

These recommendations align with the NIST AI Risk Management Framework {{nist2023airmf}}, which organizes AI risk management into governing, mapping, measuring and managing functions. Mapping corresponds to identifying where a team sits on verifiability and stakes; measuring to tracking review load and security findings alongside throughput; and managing to adopting proportionate norms such as Atlas's restrictions on authentication code.

### 6.5.2 For technical debt

The growth in pull request size and the thinning of authorship carry a risk that the repository metrics in this study could not measure over 26 weeks. Technical debt was originally framed as the deliberate acceptance of imperfect code to ship sooner, with interest paid later {{cunningham1992wycash}}, and has since been developed into a broader account of design and code shortcuts that accrue cost {{kruchten2012td}}. Code that nobody on a team fully remembers writing is a plausible form of such debt, because its maintenance requires comprehension that was never acquired. Whether the larger, less-authored pull requests seen in Atlas and Beacon translate into higher maintenance cost is a question that requires a longer window.

### 6.5.3 For research

Methodologically, the study suggests that evaluations of AI coding assistants should report the verifiability and stakes of the work studied, since these appear to condition the effect. The convergent design was valuable precisely because the quantitative strand alone would have reported "no effect" for two of three teams.

## 6.6 Threats to validity

Section 3.9 set out the threats anticipated at the design stage. Here they are revisited in the light of the results, under the headings commonly used in empirical software engineering {{wohlin2012experimentation;runeson2009case}}.

**Construct validity.** Pull requests merged per engineer-week is a proxy for throughput that is sensitive to changes in how work is split into pull requests. Since pull requests grew larger, throughput may understate the volume of change. Static-analysis findings measure detected weaknesses, not vulnerabilities in production. Survey items were single-item measures and were not validated as scales.

**Internal validity.** The interrupted time series controls for pre-existing trends but not for co-occurring events. The Atlas security episode illustrates this: the fall after week 12 is consistent with the team agreement but also with increased vigilance and growing familiarity, which participants themselves proposed. The company-wide rollout left no untreated comparison team.

**External validity.** The study concerns one company, three teams and one assistant over 26 weeks. The teams were selected precisely because they differed on verifiability and stakes (Section 3.3.3), so the model in Figure 6.1 was partly anticipated by the sampling; what the data added was the role of norms and the direction in which verification effort moved. The model is offered as an analytical generalization to be tested, not a statistical one.

**Conclusion validity.** With 52 weekly observations per team, the ITS estimates are imprecise. Atlas's level change of 0.18 is compatible with no effect and with an effect almost as large as Beacon's, so the claim that Atlas's throughput "changed little" should be read as "was not shown to change". The survey subsamples of 11 to 14 respondents support only descriptive comparison.

**Reliability.** Thematic analysis was conducted by a single researcher, with a sample of coded transcripts and the thematic map reviewed by the supervisor; this is a limitation that reflexive approaches acknowledge rather than remove, and the analytic journal described in Section 3.7.3 records the decisions made. The tech leads who contributed heavily to Theme 5 were also those responsible for the norms, which may have inclined them to credit those norms.

## 6.7 Summary

Integration showed that the repository, survey and interview data told a largely consistent story, with the one partial discordance located in learning, which the metrics could not observe. The assistant accelerated work where code was easy to verify, changed little where data correctness dominated, and in Atlas redistributed effort from writing to reviewing while briefly raising security findings until the team adopted explicit norms. The verifiability × stakes × norms model summarizes these differences, and it is broadly consistent with the literature while qualifying claims of a uniform productivity effect. Chapter 7 concludes the thesis by restating its contributions, recommendations and directions for further work.
