# Chapter 1: Introduction

This chapter introduces the problem the thesis addresses, the organization in which it was studied, and the questions that structure the work. It begins with the rapid arrival of AI coding assistants in professional software development and the uneven evidence about what they change (Section 1.1). It then describes the case company and its rollout of an assistant (Section 1.2), states the research problem (Section 1.3), and sets out the aim and research questions (Section 1.4). Sections 1.5 to 1.7 summarize the research approach, the contributions claimed and the boundaries of the study. The chapter closes with an outline of the thesis (Section 1.8).

## 1.1 Background and motivation

For most of the history of software engineering, the tools that sat inside a programmer's editor were deterministic. A compiler rejected a program or it did not; a linter flagged a pattern by rule; code completion offered the names of methods that actually existed on an object. The arrival of large language models (LLMs) trained on source code changed the character of the editor. Models such as Codex showed that a system trained on public repositories could produce functionally correct programs from a natural-language description for a meaningful share of benchmark problems, and that sampling many candidates raised that share further {{chen2021codex}}. Parallel work showed that synthesis ability grows steadily with model size {{austin2021mbpp}}, and that, with large-scale sampling and filtering, generated programs could compete in programming contests against human entrants {{li2022alphacode}}. Within a short period these research systems became commercial products: assistants embedded in the integrated development environment (IDE) that complete lines and whole functions as the programmer types, and chat panels that answer questions, explain code and propose changes.

The speed of this transition has outpaced the evidence about its consequences. The most widely cited early result is a controlled experiment in which developers given access to an assistant completed a well-specified programming task considerably faster than a control group {{peng2023copilot}}. Telemetry-based studies found that developers' perceptions of their own productivity tracked the rate at which they accepted suggestions more closely than other usage measures {{ziegler2022maps;ziegler2024cacm}}. These studies are valuable, but they share a unit of analysis: the individual developer working on a task, often a task chosen by the researchers. Software in organizations is not produced that way. It is produced by teams, through pull requests (PRs) that are reviewed by colleagues, tested in continuous integration, scanned by static analysis tools, merged, deployed and occasionally reverted. A suggestion that saves its author two minutes may cost a reviewer ten, or it may introduce a defect that surfaces weeks later in production. Whether an assistant makes a team more productive is therefore a different question from whether it makes a person faster at a task.

There are also reasons to expect the effect to differ between teams. Interaction studies suggest that programmers use assistants in at least two distinct modes, accelerating through work they already understand and exploring options when they do not {{barke2023grounded}}, and that developers avoid assistants when the output is hard to control or fails non-functional requirements {{liang2024survey}}. Security studies find that generated code can contain known classes of vulnerability {{pearce2022asleep}} and that users with assistant access may write less secure code while feeling more confident about it {{perry2023insecure}}, although the size of that risk appears to depend on the task and setting {{sandoval2023lostatc}}. A team building payment services under regulatory scrutiny and a team building user-interface components that are checked visually on every change do not face the same costs of error. If those costs shape how engineers use the assistant, then organizational averages could conceal opposing effects.

Finally, the question of how to measure productivity is itself contested. The SPACE framework argues that no single metric captures developer productivity and that measures of satisfaction, performance, activity, communication and efficiency should be read together {{forsgren2021space}}. Research on software delivery performance has popularized a small set of delivery metrics, commonly called the DORA metrics, that relate throughput and stability {{forsgren2018accelerate}}. A rise in the number of merged PRs, read alone, is an activity measure; it says little about whether the work was the right work, whether review became a bottleneck, or whether quality debts were being accumulated in the sense first described by {{cunningham1992wycash|n}} and later theorized by {{kruchten2012td|n}}. A study of AI adoption that reports throughput alone risks mistaking motion for progress.

These observations motivate a study that follows real teams through an adoption, measures several dimensions of their work rather than one, and asks engineers to explain the patterns that the measures reveal.

## 1.2 The case: Lanternfish Systems

The study was conducted at Lanternfish Systems, a business-to-business software-as-a-service company that builds scheduling and payroll software for clinics. At the time of the study the company employed 140 engineers organized into product and platform teams, each owning a set of services and repositories. All code changes passed through PRs on a shared hosting platform, with at least one approving review required before merge, automated test suites in continuous integration, and a static application security testing (SAST) tool that scanned every PR.

On March 3, 2025, the company made a commercial AI coding assistant available to all engineers. The assistant offered inline code completion in the IDE and a chat panel that could answer questions about the open file and generate or explain code. It is referred to throughout this thesis simply as "the assistant"; the specific product is not named, both to protect the company's anonymity and because the argument does not depend on the vendor. The rollout was uniform: every engineer received access on the same day, with a short internal guide on acceptable use and no team-specific instructions. This uniformity is methodologically useful. Because the intervention was identical across the organization, differences in what happened next can be attributed with more confidence to the teams than to the tool.

Three teams were selected as embedded cases, chosen to vary on the characteristics that the literature suggests should matter: the language and stack, the ease with which output can be checked, and the cost of an error reaching production.

- **Atlas** owns the payments and payroll backend, a set of Kotlin and Java services that calculate pay, move money and hold personal data. It had 16 engineers. Its code is high-stakes and subject to regulatory audit.
- **Beacon** owns the customer-facing web front end, written in TypeScript with React. It had 15 engineers. Much of its work is visible on screen and is exercised by component tests and visual review on every change.
- **Cormorant** owns the data platform and reporting pipelines, written in Python and SQL. It had 13 engineers. Its central concern is the correctness of data that clinics rely on for reporting and billing reconciliation, where errors may be silent rather than visible.

The teams are described in more detail in Chapter 3, and their characteristics are summarized in Table 3.1.

## 1.3 Problem statement

The problem this thesis addresses can be stated simply. Engineering organizations are adopting AI coding assistants on the strength of evidence gathered mainly at the level of individuals and tasks, but the outcomes they care about are produced at the level of teams, through practices of writing, reviewing and testing code that the assistant may alter. There is little published evidence that follows multiple teams in one organization through the same adoption, combines repository data with engineers' own accounts, and asks why the outcomes differ.

This gap matters for three audiences. For engineering leaders, it means that decisions about rollout, policy and measurement are being made with an incomplete picture; an organization that sees its average throughput rise may not notice that one team's review load has grown or that another's security findings have increased. For researchers, it means that the mechanisms linking assistant use to team outcomes remain largely theorized rather than observed in context. For engineers themselves, it means that the norms they develop to use the assistant well, which this study found to be decisive, are rarely documented or shared.

## 1.4 Aim and research questions

The aim of this thesis is to explain how and why the adoption of an AI coding assistant produced different outcomes in three software teams within the same organization, and to derive from that explanation a team-level model that can guide adoption decisions elsewhere.

Three research questions follow from this aim.

- **RQ1.** How did measurable delivery and quality indicators change in each team in the 26 weeks after adoption?
- **RQ2.** How did engineers use the assistant, and how did their practices of writing, reviewing and testing code change?
- **RQ3.** Which team-level conditions explain the differences between teams?

The questions are ordered by the kind of answer each requires. RQ1 is descriptive and quantitative: it asks what changed in the repository record, team by team, and is answered in Chapter 4. RQ2 is descriptive and interpretive: it asks what engineers did with the assistant and how their work practices shifted, and is answered by the survey results in Chapter 4 and the interview analysis in Chapter 5. RQ3 is explanatory and requires the two strands to be brought together; it is answered in Chapter 6, where the joint display and the proposed model are presented.

## 1.5 Research approach

The study uses a convergent mixed-methods design {{creswell2018mixed}} within an embedded multiple-case study {{runeson2009case;easterbrook2008selecting}}. The case is Lanternfish Systems; the embedded units of analysis are the three teams. A case study is appropriate because the phenomenon of interest, the adoption of a tool within working teams, cannot be separated from its context without losing the very conditions that the study seeks to explain. A multiple-case design allows the teams to be compared under a common intervention, and a mixed-methods design allows the study to ask both what changed and why.

The quantitative strand mined the PR history of each team's repositories for 26 weeks before adoption (September 2, 2024 to March 2, 2025) and 26 weeks after (March 3 to August 31, 2025). Seven indicators were extracted, covering throughput, cycle time, change size, review effort, security findings, reverts and testing. Weekly PR throughput was modeled with a segmented interrupted time series (ITS) regression for each team, which estimates both an immediate change in level and a change in trend at the point of adoption while accounting for the pre-existing trend. The model is specified in Equation (3.1).

The qualitative strand began with a survey of the engineers in the three teams, of whom 38 of 44 responded, and continued with 18 semi-structured interviews, six per team, with engineers of different seniority. The interviews were analyzed using reflexive thematic analysis {{braun2006thematic}}. The two strands were collected over overlapping periods and analyzed separately before being integrated in a joint display, which sets quantitative results, qualitative themes and the inferences drawn from their combination side by side.

The study was designed and is reported with reference to the ACM SIGSOFT Empirical Standards for case studies, questionnaire surveys and mixed-methods research {{ralph2020standards}}. Productivity is interpreted throughout through the dimensions of the SPACE framework {{forsgren2021space}} rather than through throughput alone. Chapter 3 justifies each of these choices in detail and discusses the threats to validity they entail.

## 1.6 Contributions

The thesis makes four contributions.

First, it provides a team-level, longitudinal account of AI assistant adoption in a production setting, combining 52 weeks of repository data with survey and interview evidence from the same engineers. This complements the predominantly individual-level and task-level evidence reviewed in Chapter 2.

Second, it shows that a uniform intervention produced divergent outcomes. In this case, the front-end team showed a clear increase in throughput and a shorter cycle time; the data team showed almost no change; and the payments team showed a smaller and uncertain throughput gain accompanied by larger PRs, heavier review and a temporary rise in security findings. That rise reversed after the team adopted an explicit agreement on assistant use in the twelfth week. These patterns are reported in Chapter 4 and explained in Chapters 5 and 6.

Third, it proposes a team-level adoption model in which three conditions, the verifiability of the team's tasks, the stakes of being wrong, and the presence of explicit norms for assistant use, jointly explain how the assistant's effect is distributed across SPACE dimensions and how much technical-debt risk it introduces. The model is presented in Chapter 6.

Fourth, it offers practical recommendations for engineering organizations, framed in terms compatible with the NIST AI Risk Management Framework {{nist2023airmf}}, and identifies the provenance and licensing of the code used to train assistants as an issue that team-level governance cannot resolve on its own {{kocetkov2022stack;li2023starcoder}}.

## 1.7 Scope and delimitations

Several boundaries should be stated at the outset. The study concerns a single organization and three of its teams. It does not claim that the specific magnitudes observed would recur elsewhere; its claims to transfer rest on analytical rather than statistical generalization, in the sense that the proposed model should be tested against other cases rather than assumed to hold for them.

The study evaluates the assistant as deployed, not as a technology. It does not compare assistant products or model versions, and it does not measure the accuracy of individual suggestions. It observes the consequences of use in the repository and the accounts engineers give of that use.

The quantitative strand is limited to indicators that can be extracted reliably from PR data and SAST results. It does not include deployment frequency, change failure rate or time to restore service, because these were measured at the level of services shared between teams and could not be attributed cleanly to any one of the three. Nor does it include direct measures of developer satisfaction or well-being; those dimensions are addressed through the survey and interviews.

The observation window ends 26 weeks after adoption. Effects that take longer to appear, notably the accumulation or repayment of technical debt and the long-term development of junior engineers' skills, are discussed as open questions rather than findings.

Finally, the study cannot separate the effect of the assistant from everything else that changed at the company during the period. The ITS design, the use of a pre-adoption trend, and the comparison across teams mitigate this problem but do not remove it. These and other threats to validity are examined in Chapter 3 and revisited in Chapter 6.

## 1.8 Structure of the thesis

The remainder of the thesis is organized as follows.

Chapter 2 reviews the literature on which the study builds. It traces the development of code-generating language models, assesses the evidence on their productivity effects, examines studies of how programmers interact with assistants and of the security of the code they produce, reviews research on modern code review, and discusses frameworks for measuring developer productivity and technical debt. It concludes by identifying the gap that this study addresses.

Chapter 3 describes the research design and methods: the case and its teams, the repository mining procedure and metric definitions, the interrupted time series model, the survey and interview procedures, the analysis of each strand, the integration strategy, the threats to validity and the ethical safeguards.

Chapter 4 presents the quantitative results: the repository metrics before and after adoption for each team, the time series estimates, the trajectory of security findings in the Atlas team, and the survey responses.

Chapter 5 presents the qualitative results, organized around five themes developed from the interviews and illustrated with extracts from engineers in all three teams.

Chapter 6 integrates the two strands in a joint display, presents the proposed adoption model, compares the findings with the literature reviewed in Chapter 2, and discusses implications for practice and research together with the limitations of the study.

Chapter 7 summarizes the answers to the three research questions, restates the contributions and suggests directions for future work.

Three appendices contain the survey instrument (Appendix A), the interview guide (Appendix B), and the metric definitions and extraction queries (Appendix C).

The next chapter turns to the literature, beginning with the language models on which AI coding assistants are built and moving toward the team-level questions that the existing evidence leaves open.
