# Chapter 7: Conclusion

This thesis set out to understand what happened when three software teams at one company adopted the same AI coding assistant on the same day. This final chapter summarizes the answers to the research questions, states the contributions of the study, offers recommendations for practice, acknowledges the main limitations and proposes directions for further research.

## 7.1 Summary of findings

The study combined repository mining of pull requests over 26 weeks before and 26 weeks after adoption with a survey of 38 of the 44 engineers in the three teams and 18 interviews, integrated in a convergent mixed-methods design.

In answer to RQ1, measurable delivery and quality indicators changed differently in each team. Beacon, the front-end team, showed a clear rise in throughput, from 4.2 to 4.7 pull requests merged per engineer-week, with an estimated level change of 0.46 whose confidence interval excluded zero, and its median cycle time fell from 19.8 to 15.6 hours. Atlas, the payments and payroll team, showed a small and uncertain throughput gain, larger pull requests, more review comments and a higher ratio of test to production code. Its security findings rose to 1.14 per 1,000 changed lines in the first twelve weeks and fell to 0.71 after the team adopted an explicit agreement. Cormorant, the data platform team, showed essentially no change. None of the teams showed a meaningful change in trend after adoption.

In answer to RQ2, engineers used the assistant mainly for boilerplate, tests and learning unfamiliar APIs. Accepting suggestions partly replaced writing code, which thinned authors' knowledge of their own pull requests and moved verification effort toward reviewers. Engineers calibrated their trust to the cost of being wrong, and junior engineers gained speed while voicing concern about their own skill formation.

In answer to RQ3, the differences between teams were best explained by three team-level conditions: how readily errors in the team's code could be revealed by running it, how costly an undetected error would be, and whether the team had made its norms for the assistant's use explicit.

## 7.2 Contributions

The study makes three contributions. The first is empirical. It provides a within-company, multiple-team comparison of AI assistant adoption in which the tool, the rollout date and the wider engineering culture were held constant, so that differences between teams cannot be attributed to differences between products or organizations. Most published evidence comes either from controlled tasks or from aggregated usage data, and this design addresses the team level that lies between them.

The second contribution is conceptual: the verifiability × stakes × norms model presented in Figure 6.1. The model locates the effect of an assistant in the team rather than in the tool. It predicts not only whether output will rise but where verification effort will go, and it treats explicit norms as a moderator that can relocate that effort. Read through the SPACE framework {{forsgren2021space}}, it explains why a team whose throughput barely moved, such as Atlas, may nevertheless have undergone a substantial change in how its work was distributed.

The third contribution is methodological. The study shows the value of integrating repository metrics with engineers' accounts. Two of the three teams would have been described as showing no meaningful effect by the quantitative strand alone; the joint display in Table 6.1 shows that in both cases something important had changed in practice.

## 7.3 Recommendations

For engineering leaders, the findings suggest four recommendations, each offered with the caution that they derive from one company.

First, assess an assistant's effect team by team, and read throughput together with review load, pull request size and quality indicators rather than alone. Company-wide averages are likely to conceal both gains and redistributions.

Second, agree explicit norms at the point of adoption rather than waiting for a problem. The three norms observed here were different in form, but each specified how generated code should be identified, tested or explained, and each fitted the team's work. Atlas's experience suggests that the absence of such norms in the early weeks carries a cost.

Third, treat review capacity as a constraint. When authors accept more and write less, reviewers absorb the difference. Practices such as Beacon's explain-it-to-review rule, which returns responsibility for understanding to the author, appear to help.

Fourth, embed these practices in an organizational risk process. The NIST AI Risk Management Framework {{nist2023airmf}} offers one structure for governing, mapping, measuring and managing such risks, and the dimensions of verifiability and stakes give a practical way to map them at team level.

## 7.4 Limitations

The study's limitations were discussed in Section 6.6 and are summarized here. It concerns a single company, three teams and one assistant, observed over 26 weeks. The company-wide rollout left no untreated comparison group, so the interrupted time series could not rule out co-occurring events, and the fall in Atlas's security findings after week 12 cannot be attributed to the team agreement alone. The repository metrics are proxies; static-analysis findings measure detected weaknesses, not exploitable vulnerabilities. The survey items were single items rather than validated scales, and the qualitative analysis was conducted by one researcher. The model in Figure 6.1 should therefore be treated as a proposition to be tested rather than an established result.

## 7.5 Directions for further research

Four lines of further work follow. First, the model should be tested in other organizations and domains, ideally where adoption is staggered so that later-adopting teams can serve as comparisons. Second, longer observation windows are needed to establish whether larger, less-authored pull requests accumulate maintenance cost. The technical debt literature {{cunningham1992wycash;kruchten2012td}} suggests that the cost of such code may appear only when it must be changed, well after the 26 weeks studied here. Third, the concern of junior engineers about skill formation deserves direct study, with measures of learning rather than of delivery. Fourth, questions of provenance remain open. Code models are trained on large collections of public source code, and work on permissively licensed datasets and opt-out processes {{kocetkov2022stack;li2023starcoder}} shows that the licensing status of training data is itself a governance question. This study did not examine whether generated code at Lanternfish resembled licensed sources, and organizations adopting assistants will need policies on that question.

## 7.6 Closing remarks

The engineers interviewed for this study rarely spoke of the assistant as something that had happened to them. They spoke of it as something their team had decided how to use. That is perhaps the most practical lesson of the thesis. The same tool sped up one team, left another largely unchanged and, in a third, moved the hardest work from the keyboard to the review. In each case the outcome depended on the kind of code the team wrote, the cost of getting it wrong and the agreements the team made. Pairing with the machine, on this evidence, is a team practice to be designed rather than a capability to be switched on.
