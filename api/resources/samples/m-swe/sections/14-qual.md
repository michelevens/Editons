# Chapter 5: Results: Engineers' Accounts

Chapter 4 reported what changed in the repositories and what the survey respondents said about the assistant in aggregate. This chapter turns to how engineers explained those changes. It reports the qualitative strand of the study: eighteen semi-structured interviews, six in each of the Atlas, Beacon and Cormorant teams, analyzed with the reflexive thematic approach described in Chapter 3 {{braun2006thematic}}. The chapter addresses RQ2, which asks how engineers used the assistant and how their practices of writing, reviewing and testing code changed, and it begins to address RQ3 by attending throughout to differences between the teams. Interpretation against the repository data and the published literature is deferred to Chapter 6; here the aim is to stay close to what participants said, while making clear where accounts converged and where they diverged.

Five themes were constructed. They are presented in the order in which they move from the individual engineer at the keyboard, through the review of code by colleagues, to the team as a unit that sets its own rules. Each theme is illustrated with excerpts from the interviews. Participants are identified by pseudonym, team and seniority. Excerpts have been lightly edited to remove false starts and identifying detail; omissions are marked with an ellipsis.

## 5.1 Overview of the themes

Table 5.1 summarizes the five themes, their sub-themes and the number of interviews in each team that contributed coded material to each theme. The counts are offered as a guide to how widely a pattern was shared, not as a measure of its importance. In reflexive thematic analysis a theme earns its place by the coherence of the story it tells about the data rather than by frequency {{braun2006thematic}}, and several of the most consequential observations in this chapter came from a small number of participants who were well placed to see them, such as the three tech leads.

**Table 5.1** Themes, sub-themes and interview sources.

| Theme | Sub-themes | Atlas (n = 6) | Beacon (n = 6) | Cormorant (n = 6) | Total (n = 18) |
|---|---|---|---|---|---|
| 1. "Autocomplete for the boring parts" | Scaffolding and glue code; tests as the first beneficiary; where acceleration stops | 5 | 6 | 5 | 16 |
| 2. "Reviewing a stranger's code" | Bigger changes, thinner authorship; the reviewer as first reader; review fatigue | 6 | 4 | 3 | 13 |
| 3. "Trust scales with stakes" | Pricing the cost of being wrong; the security episode; correctness that cannot be seen | 6 | 3 | 5 | 14 |
| 4. "Who learns what" | Juniors: speed and unease; seniors: a reference, not an author; unfamiliar territory | 4 | 5 | 4 | 13 |
| 5. "Norms, not tools" | Atlas's team agreement; Beacon's explain-it-to-review rule; Cormorant's rule on generated SQL; from tacit to explicit | 6 | 5 | 5 | 16 |

*Note.* Cells give the number of interviews in each team containing at least one coded extract assigned to the theme. Interviews typically contributed to several themes.

## 5.2 Theme 1: "Autocomplete for the boring parts"

The phrase that gives this theme its name was used by Jamal, a senior Beacon engineer, but versions of it appeared in sixteen of the eighteen interviews. Engineers in all three teams located the assistant's value in the parts of their work they regarded as necessary but unrewarding: boilerplate, repetitive test cases, data-transfer objects, configuration, and the glue code that connects one well-understood component to another. This matches the survey, in which boilerplate and tests was the most frequently selected use (32 of 38 respondents, Table 4.3).

### 5.2.1 Scaffolding and glue code

Participants described the assistant as most useful when they already knew what the code should look like and simply did not want to type it. The acceleration was described in concrete, almost physical terms: fewer keystrokes, less switching to documentation, less time spent on "plumbing".

> If I'm wiring a new form into the API client, I know exactly what it's going to be. Props, the hook, the loading state, the error state. I type the first line and it gives me the next fifteen, and they're basically what I would have written. That's where it pays for itself. (Jamal, Beacon, senior)

> Half of a Kotlin service is ceremony. Mappers, request classes, the same validation annotations you've written a hundred times. It's good at ceremony. (Mei, Atlas, mid)

Owen, Beacon's tech lead, related this to the character of the codebase: the front end consisted largely of components following a few established patterns, so the team's own code supplied the assistant with good examples.

### 5.2.2 Tests as the first beneficiary

Writing tests was named more often than any other single task as the place where the assistant changed engineers' habits. For some, the change was a matter of speed; for others, it altered whether tests got written at all.

> Before, if I had a function with six edge cases, I'd write three tests and feel guilty. Now I write the first one and it offers me the other five. I still have to check they test the right thing, but the cost of being thorough dropped a lot. (Ade, Atlas, mid)

This account is consistent with the rise in test lines changed per 100 production lines in Atlas, from 41 to 52 (Table 4.1). It should not be read as the whole explanation, however. Atlas engineers also attributed the increase to the team agreement of week 12, which made tests mandatory for any assistant-generated function touching money or personal data (Section 5.6.1). Priya suggested that both mechanisms operated together: the assistant made tests cheaper to write, and the agreement made them obligatory, so "the rule didn't hurt as much as it would have a year ago."

Not every participant regarded generated tests as an unqualified gain. Erik, a staff engineer in Cormorant, warned that tests produced from the implementation tended to restate it.

> It writes tests that pass. That's the problem. It reads the function and writes a test that checks the function does what the function does. If the function is wrong, the test is wrong in the same way. (Erik, Cormorant, staff)

Erik's concern was echoed, in milder terms, by two Atlas engineers, and it anticipates the theme of verification taken up in Section 5.3.

### 5.2.3 Where acceleration stops

Engineers were equally clear about where the assistant did not help. In Atlas, the boundary was domain logic: payroll calculations, tax and deduction rules, and the reconciliation of payments, all of which depended on regulatory knowledge and on decisions recorded in tickets rather than in code. In Cormorant, the boundary was the meaning of data.

> It can write a perfectly reasonable SQL join. What it doesn't know is that in our warehouse a shift with no end time means the clinic forgot to close it, not that the person is still working. Every query is full of things like that. (Lucía, Cormorant, mid)

> The assistant can make a query that runs. Making a query that's right is a different job, and that job is most of our job. (Dana, Cormorant, tech lead)

Cormorant participants were the most likely to describe the assistant as helpful but marginal. Kwame, a data engineer, described using it heavily for Python utilities and SQL drafting, which is consistent with nine of Cormorant's eleven respondents selecting SQL writing as a use, while insisting that the time saved was "eaten by checking." This tension between frequent use and modest benefit is consistent with Cormorant's lower agreement that the assistant made them more productive (mean 3.4, against 4.2 in Beacon) and with the absence of any level change in Cormorant's throughput (Table 4.2). The convergence is examined in Chapter 6.

## 5.3 Theme 2: "Reviewing a stranger's code"

The second theme concerns what happened after code was written. Thirteen participants, including all six in Atlas, described a shift in the work of verification away from the author and toward the reviewer. The theme's title comes from Grace, Atlas's tech lead, who described reviewing a pull request with substantial assistant-generated content as "reviewing a stranger's code that happens to have your colleague's name on it."

### 5.3.1 Bigger changes, thinner authorship

Participants in Atlas and Beacon noticed that pull requests had grown. Some explained the growth as a direct consequence of cheaper code: when a helper function, its tests and its documentation can be produced in a few minutes, engineers bundle more into a single change. Others linked it to a weakening of the author's relationship with the code.

> When you write every line yourself, you've reviewed it once already by the time anyone else sees it. When half of it was suggested, you've only skimmed it. The PR gets bigger and the author knows less of it. (Tomasz, Atlas, staff)

Tomasz's distinction between having written code and having accepted it was the most frequently restated idea in this theme. It is consistent with the growth in median changed lines per pull request in Atlas (118 to 164) and Beacon (96 to 131) reported in Table 4.1, although the repository data cannot show how much of that code was generated.

### 5.3.2 The reviewer as first reader

Reviewers described a change in what review was for. Several Atlas engineers said that, before adoption, they had treated review partly as a way of sharing knowledge and checking design choices, trusting the author to have tested the details. After adoption, they felt they were increasingly the first person to read some of the code closely.

> I used to review for "is this the right approach". Now I review line by line again, like I did when I was new, because I don't know if anyone has actually read this line. (Priya, Atlas, senior)

> You ask "why did you do it like this?" and the answer is "that's what it gave me". Which is honest. But then the conversation is between me and the assistant, and the assistant isn't in the meeting. (Grace, Atlas, tech lead)

Grace's remark points to a particular loss: the explanatory conversation that review normally involves. This is the problem that Beacon's explain-it-to-review rule was designed to address (Section 5.6.2). The rise in mean review comments per pull request in Atlas, from 4.6 to 5.8 (Table 4.1), is consistent with these accounts of closer, line-level review, and Atlas respondents agreed more strongly than the other teams that reviewing teammates' pull requests had become harder (3.9, against 3.1 in Beacon and 3.2 in Cormorant).

### 5.3.3 Review fatigue

A smaller group spoke of fatigue. Leon, a junior Atlas engineer, said he found himself approving changes from senior colleagues more quickly than he should because they were long and "looked fine." Rui, in Beacon, described the generated code as stylistically uniform in a way that made errors harder to spot:

> Human code has fingerprints. You can tell where someone was unsure, where they rushed. Generated code is smooth all the way through, so your eye slides over it. (Rui, Beacon, mid)

Beacon and Cormorant participants were less likely to describe review as burdensome. Beacon engineers attributed this to the visibility of front-end errors, most of which surfaced in the browser or in snapshot tests. In Cormorant, pull requests changed little in size, and review against sample data had, in Ivan's words, "always assumed the author might be wrong." The theme was strongest where code was hardest to check by running it.

## 5.4 Theme 3: "Trust scales with stakes"

The third theme describes how engineers decided whether to accept a suggestion. Participants did not report a fixed level of trust in the assistant. Instead they described adjusting their scrutiny to the consequences of the code being wrong. This calibration was visible in all three teams, but it took different forms because the teams faced different kinds of risk.

### 5.4.1 Pricing the cost of being wrong

Several participants described an informal, almost economic, reasoning about each suggestion.

> If it's a log message, I accept and move on. If it's a date calculation, I read it twice. If it's anything that moves money, I read it, I test it, and honestly I usually rewrite it so I know it. (Mei, Atlas, mid)

> For CSS I'll take it on faith and look at the screen. For anything in the auth flow I'm much slower. Same tool, different me. (Hana, Beacon, junior)

These accounts are consistent with the survey, where respondents expressed low trust in suggestions for security-sensitive code (mean 2.3) and reported reviewing assistant-generated code more carefully than their own (3.9). Several participants admitted, however, that time pressure eroded this calibration.

### 5.4.2 The security episode in Atlas

The most consequential event recounted in the interviews was the rise in static-analysis security findings in Atlas during the first weeks after adoption, which Chapter 4 reported as an increase from 0.82 findings per 1,000 changed lines before adoption to 1.14 in weeks 1–12 (Figure 4.2). All six Atlas participants mentioned it unprompted. Their accounts agreed on the main features. The findings were mostly of familiar kinds, among them logging of request bodies that could contain personal data, permissive input handling, and hand-rolled handling of tokens. None reached production, because the security scanner ran in the pipeline and flagged them before merge. What troubled engineers was less the findings themselves than what they implied about attention.

> Nothing got out. But we'd been very proud of that number, and it moved the wrong way in two months. When we looked at the findings, a lot of them were in code nobody remembered writing. (Grace, Atlas, tech lead)

> I asked it for a quick token refresh helper and it gave me something that worked. It also printed the token in a debug log. I didn't see that. The scanner did. (Leon, Atlas, junior)

Ade summarized the point several participants made: "It doesn't write broken code. It writes code that's wrong in ways that work." The episode led directly to the Atlas team agreement described in Section 5.6.1.

### 5.4.3 Correctness that cannot be seen

In Cormorant, the stakes were framed not as security but as data correctness. Reports produced by the data platform informed clinics' payroll reconciliation and staffing decisions, and an incorrect aggregation could pass every test while producing plausible but wrong figures. Participants described the assistant's SQL as fluent, which made it dangerous.

> A wrong number in a report looks exactly like a right number. That's the whole problem with our work, and the assistant makes it worse because it's confident and quick. (Noor, Cormorant, junior)

Cormorant engineers consequently described trust as uniformly low rather than calibrated case by case; Erik treated every generated query "as a hypothesis."

Beacon participants, by contrast, described themselves as trusting the assistant more freely for most work. They gave the same reason in different words: in the front end, being wrong was usually cheap and quick to detect. Sofia said that "the browser tells you within a second," and Jamal contrasted this with "a payroll bug you find at the end of the month." Only three Beacon participants contributed material to this theme, all concerning authentication and form validation, which they regarded as the exceptions in their codebase.

## 5.5 Theme 4: "Who learns what"

The fourth theme concerns learning. Participants described the assistant as changing how engineers acquired knowledge, and they disagreed about whether the change was benign. The disagreement fell broadly along lines of seniority.

### 5.5.1 Juniors: speed and unease

The four junior participants (Leon, Hana, Bea and Noor) all described gaining speed, and all expressed some unease about it. Their concern was not that the assistant was wrong but that it was right in ways they could not yet have produced themselves.

> I'm shipping things I couldn't have written six months ago. I'm not sure I can write them now either. If you took it away tomorrow, I'd be slower than I was before I started using it, because I've stopped building the muscle. (Bea, Beacon, junior)

> My lead asks me why I chose a window function and I don't always have an answer. I chose it because it was suggested and it worked. (Noor, Cormorant, junior)

Hana described a deliberate, self-imposed response: she disabled inline completion for one afternoon a week to practice "writing from a blank file."

### 5.5.2 Seniors: a reference, not an author

Senior and staff engineers described a different relationship. They used the assistant's chat mostly as a reference that was faster than documentation, and accepted inline completions mainly for code they could have written without thinking.

> I use it like I used to use a colleague who knew the standard library better than me. "What's the idiomatic way to do this in Kotlin now?" Then I decide. (Tomasz, Atlas, staff)

> I don't let it design anything. I let it remind me. (Ivan, Cormorant, senior)

Several senior participants expressed concern on behalf of junior colleagues. Grace and Dana both described explicitly asking junior engineers to talk through generated code in review, a practice that in Beacon had already been formalized (Section 5.6.2). The tech leads framed the question of skill formation as a team responsibility rather than an individual one, which bears on Theme 5.

### 5.5.3 Unfamiliar territory

Across seniority levels, the most widely praised use was learning an unfamiliar API, library or language, which the survey also rated highly (mean agreement 4.1). Mid-level engineers described this as the use that had changed their work most.

> We had to integrate a clinic's old SOAP service. Nobody here had touched SOAP in years. It got me from zero to a working client in an afternoon, and then I spent two days making it correct. That's still a good trade. (Sofia, Beacon, mid)

> I'm a Python person. When I had to fix something in an Atlas Kotlin service, it was like having a translator. (Kwame, Cormorant, data engineer, mid)

Sofia's account captures a recurring pattern: acceleration at the start of a task, followed by ordinary, unassisted effort to make the result correct.

## 5.6 Theme 5: "Norms, not tools"

The final theme is the one that most directly addresses RQ3. When asked why the teams' experiences differed, participants rarely pointed to the assistant itself, which was the same product with the same configuration across the company. They pointed instead to the agreements each team had developed about how it should be used. Three such norms were described, one per team. They differed in origin, formality and scope.

### 5.6.1 Atlas's team agreement

Atlas adopted a written team agreement in week 12 after adoption (26 May 2025), following a retrospective on the security findings described in Section 5.4.2. All six Atlas participants described it, and their accounts of its content were consistent. The agreement had three parts: an "assistant-heavy" label for pull requests in which a substantial share of the code had been generated; mandatory tests for any assistant-generated function touching money or personal data; and a rule that the assistant's chat would not be used for cryptography or authentication code.

> The label was the important part, more than people expected. It doesn't stop anything. It just tells the reviewer "read this like a stranger wrote it". Once you know that, you review differently. (Grace, Atlas, tech lead)

> The auth rule was the one people grumbled about. But honestly that's code you should be writing slowly anyway. (Priya, Atlas, senior)

Participants attributed the fall in security findings in the second half of the period, to 0.71 per 1,000 changed lines in weeks 13–26, to the agreement. They were careful about the claim. Tomasz noted that the team was also "just more awake after the scare," and Ade suggested that engineers had simply become more familiar with the tool's characteristic errors. The repository data cannot separate these explanations; the timing is nonetheless consistent with the agreement having had an effect (Figure 4.2).

### 5.6.2 Beacon's explain-it-to-review rule

Beacon's norm was lighter and arose earlier, during the first weeks after adoption. It was proposed informally by Owen and later written into the team's contribution guide. The rule required that an author be able to explain, in the pull request description or in response to a review comment, any block of code they had accepted from the assistant. An answer of "the assistant suggested it" was not accepted.

> It's one sentence in the guide and it does a lot of work. If you can't explain it, you don't understand it, and if you don't understand it, it doesn't go in. People started reading suggestions before accepting them because they knew someone would ask. (Owen, Beacon, tech lead)

Beacon participants credited the rule with keeping review manageable even as pull requests grew. Jamal described it as "moving the reading back to the author," which is the inverse of the shift that Atlas engineers had described in Theme 2. Two participants also connected it with learning: Bea said the rule was "annoying but it's the only reason I understand half my PRs."

### 5.6.3 Cormorant's rule on generated SQL

Cormorant's norm was the narrowest and the most restrictive. Dana, the tech lead, described a rule that no generated SQL could enter a production pipeline unless it had been reviewed by a second engineer and validated against a reference dataset. The rule extended an existing practice rather than creating a new one; Cormorant had long reviewed pipeline queries against sample data.

> We didn't need a new philosophy. We already didn't trust anyone's SQL, including our own. We just made it explicit that the assistant counts as "anyone". (Dana, Cormorant, tech lead)

Cormorant participants generally regarded the rule as uncontroversial. Some, however, saw it as one reason the assistant changed little in their work. Kwame observed that "if every query still goes through the same gate, the gate sets the speed," a remark that is consistent with the near-identical median cycle times before and after adoption reported in Table 4.1.

### 5.6.4 From tacit to explicit

Across teams, participants described norms as having existed tacitly before being written down. Atlas engineers said they had been "careful" with the assistant from the start, but that care had varied between individuals until the agreement standardized it. Beacon's rule formalized something Owen said several senior engineers were already doing. Cormorant's rule restated an old habit. What the interviews suggest, therefore, is not that norms were introduced into teams that lacked them, but that adoption of the assistant made tacit practices visible and, in each team, prompted their codification in a form that fitted the team's work.

> We spent the first month arguing about whether the tool was good. That was the wrong question. The question was what we wanted to be true about our code, and then how the tool fits that. (Grace, Atlas, tech lead)

## 5.7 Divergent and negative cases

Reflexive analysis requires attention to accounts that do not fit the dominant patterns. Three are worth recording. First, Erik, the Cormorant staff engineer, used the assistant rarely and regarded it as a net cost for his own work, although he accepted that it helped colleagues with unfamiliar code. His account is a reminder that team-level patterns concealed individual variation; in Cormorant, seven of eleven survey respondents used the assistant daily, and the remainder used it less often. Second, Leon in Atlas described the team agreement as having made him more anxious about using the assistant at all, so that he had "gone back to typing everything" for a period after week 12. Third, Rui in Beacon questioned whether the explain-it-to-review rule was applied consistently, observing that "senior people's PRs get waved through." These cases do not overturn the themes, but they qualify them: norms were experienced differently by different engineers, and their effects on individuals were not uniformly benign.

## 5.8 Summary

The interviews describe an assistant whose value lay mainly in routine code and tests (Theme 1), whose use moved part of the work of verification from authors to reviewers (Theme 2), and whose suggestions engineers trusted in proportion to the perceived cost of error (Theme 3). Juniors gained speed while doubting their own learning, and seniors used the assistant as a reference (Theme 4). The differences between teams were explained by participants not as differences in the tool, which was identical, but as differences in the explicit norms each team developed to govern its use (Theme 5). Chapter 6 brings these accounts together with the repository and survey results of Chapter 4 in a joint display, develops the team-level model that the combined evidence suggests, and compares both with the published literature.
