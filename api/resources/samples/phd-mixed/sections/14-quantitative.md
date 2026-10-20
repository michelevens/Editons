# Chapter 5: Quantitative Results

## 5.1 Introduction

This chapter reports the results of Phase 1, the survey of secondary teachers in the four Northern Lakes districts. Its purpose is to answer the first research question: to what extent do performance expectancy, effort expectancy, facilitating conditions and trust in dashboard data predict teachers' reported use of learning analytics dashboards? It does so by testing the six hypotheses set out in Chapter 3 (H1 to H6) against the trust-extended acceptance model, and by examining whether the sample contains distinct groups of teachers whose combinations of beliefs, intentions and use differ in ways that a single set of regression coefficients would conceal.

The chapter has a second purpose that follows from the explanatory sequential design described in Chapter 4. In a QUAN→qual design the quantitative phase does not only answer its own question; it also generates the questions that the qualitative phase must answer {{ivankova2006}} {{creswell2018}}. For that reason the chapter does not end with the hypothesis tests. It ends by setting out, as precisely as the data allow, the results that the survey could establish but could not explain, and by identifying the subgroup of teachers from whom the Phase 2 participants were selected. Those puzzles are the connecting point between the two phases, and they are taken up in Chapter 6.

The chapter proceeds in the order in which the analyses were conducted. Section 5.2 reports the response rate, the treatment of missing data and the screening of the data for distributional assumptions and clustering. Section 5.3 describes the sample. Section 5.4 presents descriptive statistics and the correlations among the study variables, including the platform log data available for a subsample of 288 teachers. Section 5.5 evaluates the measurement model through confirmatory factor analysis and addresses common method variance. Section 5.6 reports the structural model and the tests of H1 to H5, and Section 5.7 reports the latent interaction used to test H6, together with simple slopes. Section 5.8 reports a set of robustness checks, including a re-estimation of the model with logged logins as the outcome. Section 5.9 reports the latent profile analysis. Section 5.10 summarizes the hypothesis tests and gives the answer to RQ1, and Section 5.11 sets out the puzzles that were carried forward to Phase 2. Throughout, I report standardized coefficients unless otherwise stated, and I follow the conventions for reporting structural equation models recommended by {{kline2016|n}}.

## 5.2 Data screening

### 5.2.1 Response rate and exclusions

Invitations were sent to all 1,180 teachers of grades 6 to 12 in the 31 secondary schools of the four participating districts. The survey remained open for five weeks, and two reminders were sent, as described in Chapter 4. In total, 457 teachers opened the survey and answered at least one substantive item. Of these, 45 were excluded before analysis. Thirty-one had completed fewer than half of the substantive items, which was the pre-specified threshold for inclusion; most of these teachers stopped after the demographic block or after the first construct. Fourteen were excluded for inattentive responding, defined as failing the embedded instructed-response item or giving an identical response to every item across two or more consecutive construct blocks that included a reverse-worded item. The final analytic sample was therefore 412 teachers, a usable response rate of 34.9%.

A response rate of this size is typical of voluntary staff surveys conducted outside paid time, but it raises the question of whether respondents differed systematically from non-respondents. Two pieces of evidence bear on this. First, the districts supplied aggregate staff data on gender, subject department and years of service. The sample's composition on these characteristics was close to that of the invited population, with no category differing by more than four percentage points (see Section 5.3). Second, I compared early respondents (those who responded before the first reminder, n = 198) with late respondents (those who responded after the second reminder, n = 87), on the reasoning that late respondents more closely resemble non-respondents. The two groups did not differ significantly on any of the six constructs; the largest difference was on behavioral intention, where early respondents scored slightly higher (d = 0.14, p = .29). These checks cannot rule out non-response bias on unmeasured characteristics, and in particular they cannot rule out that teachers with strong feelings about the dashboard, in either direction, were more likely to respond. I return to this limitation in Chapter 8.

### 5.2.2 Missing data

Among the 412 retained cases, 3.1% of item responses were missing. Missingness was spread thinly. No case was missing more than 27% of substantive items (the maximum permitted by the inclusion rule was 50%), and 71% of cases had no missing item responses at all. At the item level, the proportion missing ranged from 0.7% to 5.2%. The highest rates were found on the trust items, especially the item asking whether the dashboard's data were accurate (5.2%), and on the final two use items (4.6% and 4.4%). The pattern on the trust items is substantively interesting. In open comments at the end of the survey, several teachers wrote that they did not know how the dashboard's figures were produced and so felt unable to say whether they were accurate. Missingness on these items may therefore carry some information about uncertainty, a point that the interviews later took up (Chapter 6).

Little's test did not reject the hypothesis that the data were missing completely at random, χ²(1,214) = 1,268.4, p = .14. Because this test has limited power and because missingness on the trust items was plausibly related to observed variables, I treated the data as missing at random rather than missing completely at random. All latent variable models were estimated by full information maximum likelihood (FIML) with robust standard errors (MLR), which uses all available information from each case and produces unbiased estimates under the missing-at-random assumption {{kline2016}} {{hair2019}}. No values were imputed and no cases were deleted listwise. For the descriptive statistics and the observed-score correlations reported in Section 5.4, scale scores were computed as the mean of the available items for each case, provided at least three-quarters of a scale's items had been answered; this rule produced complete scale scores for between 98.1% and 100% of cases, depending on the scale.

### 5.2.3 Distributions and outliers

Item and scale distributions were examined for skewness, kurtosis and outliers. All attitudinal scale scores were approximately symmetric, with skewness between −0.62 (performance expectancy) and 0.21 (facilitating conditions) and kurtosis between −0.58 and 0.47. The self-reported use scale was mildly positively skewed (skewness 0.41, kurtosis −0.52), reflecting a concentration of teachers at the lower end of the 0–5 frequency scale. Univariate values were well within the ranges that are usually treated as acceptable for maximum likelihood estimation {{kline2016}}. Mardia's coefficient of multivariate kurtosis was, however, significant (normalized estimate 6.83), which is unsurprising for 22 ordinal indicators with five response categories and is the main reason for using the robust MLR estimator rather than conventional maximum likelihood.

Monthly logins, available for 288 teachers, were strongly positively skewed (skewness 1.94, kurtosis 4.21). A small number of teachers logged in very frequently; the maximum was 31 logins in the month. Because these values were plausible (several teachers held data-coordinator roles) they were retained, but the variable was transformed as ln(logins + 1) for all inferential analyses. After transformation, skewness was 0.18.

Multivariate outliers were screened using Mahalanobis distance on the six scale scores. Nine cases exceeded the critical value at p < .001. Inspection showed that these were coherent response patterns, most commonly teachers with very high intention and very low use or the reverse, rather than evident errors. Since such patterns are central to the study's interest in the gap between intention and use, the cases were retained. Re-estimating the structural model without them changed no coefficient by more than .02 and did not change any conclusion.

### 5.2.4 Clustering of teachers within schools

Teachers were nested in 31 schools, with between 4 and 29 respondents per school (mean 13.3). Intraclass correlations for the scale scores were small to moderate: .04 for behavioral intention, .06 for use, .03 for performance expectancy, .02 for effort expectancy, .09 for trust and .07 for facilitating conditions. The comparatively higher values for trust and facilitating conditions are consistent with the possibility that these beliefs are partly shaped at the school level, by the quality of local technical support, the way leaders talk about data and the presence of structured data meetings. With 31 clusters, a full two-level structural model was not feasible for a model of this complexity, and the study's questions concerned teachers rather than schools. I therefore report single-level models as the primary analysis, and in Section 5.8 report the same models with cluster-robust standard errors that adjust for school membership.

## 5.3 Sample characteristics

Table 5.1 summarizes the characteristics of the 412 respondents. Sixty-one percent identified as female, 38% as male and 1% as another gender identity. Mean teaching experience was 12.4 years (SD 8.1), with a range from 1 to 38 years. The sample was spread across the main secondary subject areas: 22% taught mathematics, 21% English, 19% science and 16% social studies, with the remaining 22% teaching other subjects, mainly world languages, the arts, physical education, career and technical education, and special education. All four districts were represented, and the share of the sample from each district was within three percentage points of that district's share of the invited population.

**Table 5.1** Characteristics of survey respondents (n = 412)

| Characteristic | n | % |
|---|---|---|
| **Gender** | | |
| Female | 251 | 61 |
| Male | 157 | 38 |
| Another gender identity | 4 | 1 |
| **Subject area** | | |
| Mathematics | 91 | 22 |
| English | 87 | 21 |
| Science | 78 | 19 |
| Social studies | 66 | 16 |
| Other subjects | 90 | 22 |
| **Teaching experience** | | |
| 0–5 years | 94 | 23 |
| 6–10 years | 103 | 25 |
| 11–20 years | 140 | 34 |
| More than 20 years | 75 | 18 |
| **School level** | | |
| Middle school (grades 6–8) | 158 | 38 |
| High school (grades 9–12) | 254 | 62 |
| **Platform log data linked** | | |
| Consented to log linkage | 288 | 70 |
| Did not consent | 124 | 30 |

*Note.* Percentages are rounded and may not sum to 100. Mean experience 12.4 years (SD 8.1).

Of the 412 respondents, 288 (70%) consented to having their survey responses linked to the dashboard's platform logs. Because the log subsample was used for one of the validity checks and for a robustness analysis, it mattered whether consenting teachers differed from those who declined. They did not differ significantly in gender, subject, experience or any of the six constructs. The largest difference was in self-reported use, where consenting teachers reported slightly more frequent use (mean 2.26 versus 2.10; d = 0.16, p = .14). It is possible that teachers who used the dashboard less were a little less willing to have that fact recorded, and the log-based results should be read with that small asymmetry in mind.

## 5.4 Descriptive statistics and correlations

### 5.4.1 Scale descriptives

Table 5.2 reports descriptive statistics and internal consistency for each scale. The attitudinal and intention scales used a 5-point agreement format, so their midpoint is 3. Use was measured on the 0–5 frequency scale described in Chapter 4, so its midpoint is 2.5, and its mean is not directly comparable with the others.

**Table 5.2** Descriptive statistics and reliability of study variables (n = 412)

| Variable | Items | Range | M | SD | Skewness | Kurtosis | α |
|---|---|---|---|---|---|---|---|
| Performance expectancy (PE) | 4 | 1–5 | 3.71 | 0.74 | −0.62 | 0.47 | .89 |
| Effort expectancy (EE) | 4 | 1–5 | 3.42 | 0.81 | −0.31 | −0.18 | .86 |
| Facilitating conditions (FC) | 4 | 1–5 | 3.05 | 0.88 | 0.21 | −0.44 | .81 |
| Trust in dashboard data (Trust) | 5 | 1–5 | 2.94 | 0.86 | 0.12 | −0.39 | .91 |
| Behavioral intention (BI) | 3 | 1–5 | 3.58 | 0.90 | −0.48 | −0.12 | .93 |
| Self-reported use (Use) | 6 | 0–5 | 2.21 | 1.02 | 0.41 | −0.52 | .84 |
| Logins per month (n = 288) | — | 0–31 | 5.5 | 4.7 | 1.94 | 4.21 | — |

*Note.* α = Cronbach's alpha. Logins are raw monthly counts; inferential analyses used ln(logins + 1). Median logins = 4.

Three features of these descriptives frame the rest of the chapter. First, performance expectancy was the highest of the attitudinal means and was clearly above the scale midpoint: on average, teachers agreed that the dashboard could help them in their work. Second, trust in dashboard data was the lowest of the attitudinal means and sat slightly below the midpoint. Teachers who agreed, on average, that the tool was useful did not, on average, trust the data it displayed. Third, the contrast between intention and use is striking. A mean intention of 3.58 indicates that the typical teacher agreed that they intended to use the dashboard in the coming months, yet the mean of 2.21 on the use scale places the typical teacher below the midpoint of the frequency scale. Read at the item level (Appendix C, Table C.1), this meant that most teachers checked a class-level overview with some regularity but that actions which involve doing something with the data, such as planning a regrouping or contacting a family on the basis of a dashboard alert, were reported rarely. The use that did occur was, in the language of the district leaders quoted in Chapter 1, shallow.

Reliability was satisfactory for every scale. All Cronbach's alpha values exceeded .80, comfortably above the conventional threshold of .70 {{hair2019}}. Composite reliabilities, which do not assume equal loadings, are reported with the measurement model in Section 5.5.

### 5.4.2 Correlations

Table 5.3 reports the observed-score correlations among the six scales, teaching experience and logged logins. The correlations are attenuated by measurement error; the corresponding latent correlations, which are larger, are reported in Table 5.5.

**Table 5.3** Correlations among study variables

| Variable | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
|---|---|---|---|---|---|---|---|
| 1. PE | — | | | | | | |
| 2. EE | .40 | — | | | | | |
| 3. FC | .32 | .30 | — | | | | |
| 4. Trust | .41 | .27 | .28 | — | | | |
| 5. BI | .56 | .38 | .32 | .49 | — | | |
| 6. Use | .31 | .20 | .34 | .30 | .41 | — | |
| 7. Logins (ln) | .21 | .12 | .27 | .24 | .29 | .58 | — |
| 8. Experience (years) | −.06 | −.11 | .03 | −.04 | −.09 | −.07 | −.10 |

*Note.* n = 412 for correlations among variables 1–6 and 8; n = 288 for correlations involving logins. For n = 412, |r| ≥ .10 is significant at p < .05 and |r| ≥ .17 at p < .001. For n = 288, |r| ≥ .12 is significant at p < .05 and |r| ≥ .20 at p < .001.

All correlations among the six constructs were positive and significant, and all were in the direction the model predicts. The largest correlation was between performance expectancy and behavioral intention (r = .56), followed by trust and intention (r = .49). Intention correlated moderately with self-reported use (r = .41), and facilitating conditions correlated with use (r = .34) about as strongly as with intention (r = .32), which is consistent with UTAUT's specification of facilitating conditions as a direct determinant of use rather than of intention {{venkatesh2003}}. Effort expectancy had the weakest relationships with the outcomes, particularly with use (r = .20). Teaching experience was largely unrelated to the constructs; the small negative correlation with effort expectancy (r = −.11) suggests that more experienced teachers found the dashboard marginally harder to use, but the effect was small.

The correlation between self-reported use and logged logins (r = .58, n = 288) deserves separate comment, because it bears on the validity of the study's outcome measure. A correlation of this size indicates that the self-report scale was tracking real behavior: teachers who said they used the dashboard more often did, on the whole, log in more often. It also indicates that the two measures were far from interchangeable. They shared about a third of their variance. Some of the remaining variance is expected, because the use scale asked about six kinds of action rather than about logging in, and a teacher can perform several actions in one session. But the size of the gap left open the possibility that the two measures were capturing different things, and that possibility is examined further in Section 5.8.3.

The pattern of correlations with logins also differed from the pattern with self-reported use in one informative respect. Facilitating conditions correlated almost as strongly with logins (r = .27) as intention did (r = .29), while performance expectancy and trust correlated less strongly with logins than with self-reported use. One reading is that logins, as a behavioral trace, were driven partly by organizational occasions for logging in, such as scheduled data meetings, which would be associated with school-level support rather than with individual beliefs. This reading could not be tested with the survey data and was flagged for Phase 2.

## 5.5 Measurement model

### 5.5.1 Specification

Before testing the structural hypotheses, I evaluated the measurement model through confirmatory factor analysis, following the two-step approach in which the adequacy of the measurement model is established before structural paths are interpreted {{kline2016}} {{hair2019}}. The model specified six correlated latent factors. Performance expectancy, effort expectancy and facilitating conditions were each measured by four items, trust in dashboard data by five items and behavioral intention by three items, with each item loading only on its intended factor.

The six self-reported use items required a different decision. The items asked how often in the past month a teacher had performed six distinct dashboard actions. Frequencies of distinct behaviors are not obviously reflective indicators of a single latent cause in the way that attitude items are: a teacher may perform one action often and another never for reasons that have little to do with an underlying disposition to use. At the same time, the items correlated substantially with each other (α = .84), and the analytic aim was to treat use as a latent outcome so that its relationships with the predictors would be corrected for measurement error. As planned in Chapter 4, I resolved this by forming two item parcels, each the mean of three items. The first parcel combined the three actions concerned with viewing and monitoring (Use1 to Use3); the second combined the three actions concerned with acting on the data (Use4 to Use6). Parceling of this kind reduces the influence of item-specific variance and is defensible when, as here, the construct is not the focus of the measurement analysis and the items within each parcel are conceptually homogeneous {{kline2016}}. The cost is that the model cannot detect misfit at the level of individual use items, and the item-level properties of the use scale are therefore reported separately in Appendix C. With 20 attitudinal items and two use parcels, the model had 22 indicators and 194 degrees of freedom.

### 5.5.2 Model fit

The measurement model fitted the data well. The robust chi-square was significant, χ²(194) = 412.6, p < .001, as is expected with a sample of this size. The approximate fit indices all met the joint criteria proposed by {{hu1999|n}}: CFI = .956, TLI = .948, RMSEA = .052 (90% CI [.045, .059]) and SRMR = .046. The TLI was fractionally below .95, but within the range that Hu and Bentler's combination rules treat as acceptable when the SRMR is well below .08, and the upper bound of the RMSEA confidence interval was below .06. Modification indices did not suggest any cross-loading above .20 or any substantively interpretable correlated residual, and none was added. The largest modification index concerned a residual covariance between two effort expectancy items that both referred to learning to use the system. Freeing it would have improved fit slightly but was not theoretically motivated and was not adopted.

### 5.5.3 Loadings, reliability and convergent validity

Table 5.4 reports the standardized loadings, composite reliabilities and average variance extracted. All loadings were significant at p < .001 and all were at or above .69, exceeding the commonly used threshold of .50 and the stricter threshold of .70 for all but two items {{hair2019}}. Composite reliability ranged from .82 to .93, and the average variance extracted (AVE) exceeded .50 for every construct, indicating that each factor explained more variance in its indicators than was left to error {{fornell1981}}.

**Table 5.4** Confirmatory factor analysis: standardized loadings, composite reliability and AVE (n = 412)

| Construct | Indicator | λ | CR | AVE |
|---|---|---|---|---|
| Performance expectancy | PE1 | .84 | .90 | .68 |
| | PE2 | .86 | | |
| | PE3 | .79 | | |
| | PE4 | .81 | | |
| Effort expectancy | EE1 | .80 | .87 | .62 |
| | EE2 | .78 | | |
| | EE3 | .76 | | |
| | EE4 | .81 | | |
| Facilitating conditions | FC1 | .74 | .82 | .53 |
| | FC2 | .71 | | |
| | FC3 | .77 | | |
| | FC4 | .69 | | |
| Trust in dashboard data | TR1 | .83 | .91 | .67 |
| | TR2 | .85 | | |
| | TR3 | .80 | | |
| | TR4 | .82 | | |
| | TR5 | .79 | | |
| Behavioral intention | BI1 | .90 | .93 | .81 |
| | BI2 | .92 | | |
| | BI3 | .88 | | |
| Self-reported use | Parcel 1 (Use1–Use3) | .86 | .83 | .71 |
| | Parcel 2 (Use4–Use6) | .83 | | |

*Note.* λ = standardized loading; CR = composite reliability; AVE = average variance extracted. All loadings p < .001. Item wording is given in Appendix A. Model fit: χ²(194) = 412.6, CFI = .956, TLI = .948, RMSEA = .052 [.045, .059], SRMR = .046.

Facilitating conditions was the weakest of the six factors, with the lowest loadings (.69 to .77) and an AVE only modestly above .50. This is consistent with the scale's content. Its four items asked about different resources, namely access to a device, knowledge of how to use the system, compatibility with other systems in use, and the availability of help, and teachers might reasonably have one without the others. The scale was adequate for the purposes of the model, but it is worth noting at this point what it did not ask about. None of its items concerned time. This omission had been a deliberate choice, made to keep the scale close to its UTAUT source {{venkatesh2003}}, and its consequences became apparent only when the interview data were analyzed (Chapters 6 and 7).

### 5.5.4 Discriminant validity

Discriminant validity was assessed with the {{fornell1981|n}} criterion, under which the square root of each construct's AVE should exceed its correlations with every other construct. Table 5.5 shows that the criterion was met for every construct. The closest case was the pair of performance expectancy and behavioral intention, whose latent correlation (.62) was well below the square roots of their AVEs (.82 and .90). The latent correlation between trust and intention (.53) was likewise well below the square root of the trust AVE (.82), which is relevant to the substantive argument of the study: trust in the data was clearly distinct from the intention to use the tool, rather than another way of expressing it.

**Table 5.5** Latent factor correlations and discriminant validity

| Construct | PE | EE | FC | Trust | BI | Use |
|---|---|---|---|---|---|---|
| PE | **.82** | | | | | |
| EE | .46 | **.79** | | | | |
| FC | .38 | .36 | **.73** | | | |
| Trust | .45 | .31 | .33 | **.82** | | |
| BI | .62 | .42 | .37 | .53 | **.90** | |
| Use | .36 | .24 | .41 | .34 | .47 | **.84** |

*Note.* Values on the diagonal (bold) are the square roots of AVE; off-diagonal values are latent correlations from the CFA. All correlations p < .001.

### 5.5.5 Common method variance

All six constructs were measured by self-report in a single survey administration, which creates a risk that some of the shared variance among them reflects the method of measurement rather than the constructs themselves {{podsakoff2003}}. Several procedural remedies recommended by {{podsakoff2003|n}} had been built into the design (Chapter 4): respondents were assured of anonymity with respect to their schools and districts, items for different constructs were placed in separate blocks with distinct instructions, the predictor and outcome blocks were separated by the demographic section, and the use items employed a different response format from the attitudinal items.

Three statistical checks were conducted. First, in Harman's single-factor test, a single unrotated factor accounted for 31% of the variance across the attitudinal and use items, well below the 50% level at which a single method factor would be suspected to dominate. This test is insensitive, and I report it mainly because it is conventional. Second, I estimated a model in which all indicators also loaded on an unmeasured latent method factor, uncorrelated with the substantive factors. The method factor accounted for an average of 4.2% of indicator variance, compared with an average of 67% for the substantive factors, and all substantive loadings remained significant and above .62. Third, and most persuasively, the outcome could be partly validated against a source that does not share the survey method. For the 288 teachers with linked logs, the model was re-estimated with logged logins as the outcome (Section 5.8.2), and the key relationships held, though at reduced strength. Together these results suggest that common method variance inflated the relationships modestly at most and did not account for them.

### 5.5.6 Measurement invariance across gender

Because the structural results would later be interpreted across groups of teachers, I tested whether the measurement model operated equivalently for female and male teachers (n = 251 and n = 157; the four teachers who identified as another gender were too few to form a group). The configural model fitted well (CFI = .951, RMSEA = .055). Constraining the loadings to equality (metric invariance) produced a negligible change in fit (ΔCFI = −.003, ΔRMSEA = −.001), as did additionally constraining the intercepts (scalar invariance; ΔCFI = −.006, ΔRMSEA = .001). Both changes were within the thresholds commonly used to judge invariance. Mean comparisons were therefore meaningful, and none of the latent means differed significantly between female and male teachers at α = .01.

## 5.6 Structural model

### 5.6.1 Model specification

The structural model translated the hypotheses in Chapter 3 into a set of directed paths among the latent variables. Performance expectancy, effort expectancy and trust were specified as predictors of behavioral intention (H1, H2 and H3). Behavioral intention and facilitating conditions were specified as predictors of use (H4 and H5). Following UTAUT, performance and effort expectancy were not given direct paths to use; their effects were expected to operate through intention {{venkatesh2003}}. Trust was given a direct path to use in addition to its path to intention. This direct path was not hypothesized, but it was necessary for the test of H6: a model that includes a latent interaction between trust and intention must also include the main effect of trust on the outcome, or the interaction term will absorb variance that properly belongs to the main effect {{kline2016}}. The four exogenous constructs were allowed to correlate freely.

Latent interaction models estimated by the latent moderated structural equations approach do not produce the conventional chi-square and approximate fit indices. I therefore followed a two-stage procedure. In the first stage, I estimated the structural model without the interaction term and evaluated its fit in the usual way. In the second stage, I added the latent interaction between trust and intention and compared the two models using a likelihood ratio test. The coefficients reported in Table 5.6 come from the second-stage model; the coefficients from the first-stage model differed from them by no more than .02, except where noted.

### 5.6.2 Model fit

The first-stage structural model fitted the data well: χ²(197) = 431.8, p < .001, CFI = .952, TLI = .944, RMSEA = .054 (90% CI [.047, .061]) and SRMR = .055. The increase in chi-square relative to the measurement model was modest given the three additional degrees of freedom, and the increase in SRMR reflected the constraints imposed by omitting direct paths from performance and effort expectancy to use. These omitted paths were examined as an alternative model in Section 5.8.1. Adding the latent interaction significantly improved the fit of the model to the data, as indicated by a likelihood ratio test on the scaled log-likelihoods, −2ΔLL = 9.84, Δdf = 1, p = .002.

### 5.6.3 Path estimates

Figure 5.1 summarizes the estimated model, and Table 5.6 gives the full path estimates.

> **Figure 5.1** Estimated structural model (standardized coefficients, n = 412). Left: performance expectancy (β = .41***), effort expectancy (β = .12*) and trust in dashboard data (β = .29***) point to behavioral intention (R² = .48). Center: behavioral intention points to self-reported use (β = .33***). Below: facilitating conditions point directly to use (β = .27***); trust also has a small, non-significant direct path to use (β = .07, dashed). A dashed arrow from trust meets the intention→use path, labeled "Trust × BI, β = .19**". Right: self-reported use (R² = .31). Correlations among the four exogenous constructs are omitted for clarity. * p < .05, ** p < .01, *** p < .001.

**Table 5.6** Structural model: standardized path estimates (n = 412)

| Path | Hypothesis | β | SE | z | p | Decision |
|---|---|---|---|---|---|---|
| PE → BI | H1 | .41 | .06 | 6.83 | < .001 | Supported |
| EE → BI | H2 | .12 | .055 | 2.18 | .029 | Supported (weakly) |
| Trust → BI | H3 | .29 | .05 | 5.80 | < .001 | Supported |
| BI → Use | H4 | .33 | .06 | 5.50 | < .001 | Supported |
| FC → Use | H5 | .27 | .06 | 4.50 | < .001 | Supported |
| Trust × BI → Use | H6 | .19 | .065 | 2.92 | .004 | Supported |
| Trust → Use | — | .07 | .06 | 1.17 | .24 | Not hypothesized |
| **Variance explained** | | | | | | |
| R² BI | | .48 | | | | |
| R² Use | | .31 | | | | |

*Note.* Estimates from the latent moderated structural equations model, MLR with FIML. The BI → Use coefficient is the effect of intention at the mean level of trust. R² Use without the interaction term = .28.

Performance expectancy was the strongest predictor of behavioral intention (β = .41, p < .001), supporting H1. Teachers who believed the dashboard would help them do their jobs better were considerably more likely to intend to use it. This is the most robust finding of the acceptance literature, from the original Technology Acceptance Model onward {{davis1989}} {{venkatesh2003}}, and its replication here is unsurprising. What is perhaps more notable is how close the next coefficient came to it. Trust in dashboard data predicted intention with a coefficient of .29 (p < .001), supporting H3. Trust contributed to intention independently of performance expectancy, with which it was moderately correlated (latent r = .45). Put another way, among teachers who regarded the dashboard as equally useful, those who trusted its data were more likely to intend to use it. That trust should matter in this way is consistent with the integrative view of trust as a willingness to be vulnerable to another party's actions {{mayer1995}} and with the literature on reliance on automated systems, in which the decision to rely on a system depends on beliefs about its reliability as well as its capability {{lee2004}}.

Effort expectancy also predicted intention, but weakly (β = .12, p = .029). H2 was therefore supported, but only just, and the result was the least stable in the model: under cluster-robust standard errors its p-value rose to .06 (Section 5.8.4). The weakness of the effect is in line with the observation in the UTAUT synthesis that effort expectancy tends to matter early in adoption and to diminish as users become experienced with a system {{venkatesh2003}}. The district dashboard had been in place in all four districts for at least two school years, and most teachers had had time to learn its basic operation. Whether the weak effect reflected familiarity or something about what the items measured could not be determined from the survey.

Together, performance expectancy, effort expectancy and trust explained 48% of the variance in behavioral intention. This is a substantial proportion and comparable to the proportions typically reported for intention in acceptance studies, which suggests that the trust-extended model captured the main sources of variation in teachers' intentions.

Turning to use, behavioral intention predicted self-reported use (β = .33, p < .001 at the mean level of trust), supporting H4, and facilitating conditions predicted use directly (β = .27, p < .001), supporting H5. The two coefficients were of similar size. Trust had no significant direct effect on use once intention and the interaction were in the model (β = .07, p = .24); its influence on use operated through intention and, as Section 5.7 shows, through its moderation of the link between intention and use. The model explained 31% of the variance in use, considerably less than it explained in intention. That asymmetry is itself a finding. It means that knowing what teachers believed about the dashboard and what they intended to do told us a good deal about their intentions but considerably less about what they actually did.

### 5.6.4 Indirect effects

Because performance expectancy, effort expectancy and trust were hypothesized to influence use through intention, I estimated their indirect effects on use. The first-stage model (without the interaction) was used for this purpose, since indirect effects in a model with a latent interaction depend on the level of the moderator. Confidence intervals were obtained by the Monte Carlo method with 20,000 draws, which does not assume that the product of two coefficients is normally distributed. The indirect effect of performance expectancy on use through intention was .14 (95% CI [.08, .21]), the indirect effect of trust was .10 (95% CI [.05, .16]), and the indirect effect of effort expectancy was .04 (95% CI [.00, .09]). The confidence interval for effort expectancy included zero at its lower bound when rounded to two decimal places, which reinforces the conclusion that its contribution was marginal. The total effect of trust on use, combining the indirect path through intention and the small direct path, was .17 (95% CI [.07, .27]).

### 5.6.5 Control variables

To check whether the structural relationships were confounded with teacher characteristics, I re-estimated the first-stage model with years of experience, gender (female versus not female), school level (high school versus middle school) and subject area (dummy coded with "other subjects" as the reference) as covariates of both intention and use. None of the covariates had a significant effect on intention. Experience had a small, non-significant negative effect on use (β = −.07, p = .13), and teachers of mathematics reported slightly more use than the reference group (β = .10, p = .04), possibly reflecting the dashboard's richer display of assessment data in quantitative subjects. No hypothesized path changed by more than .02, and no conclusion changed. Full estimates are given in Appendix C (Table C.3). Because the covariates added little and consumed degrees of freedom, they were omitted from the models reported in the main text.

## 5.7 Moderation of the intention–use relationship by trust

H6 predicted that trust in dashboard data would moderate the relationship between intention and use, such that intention would translate into use more strongly when trust was high. The hypothesis was derived in Chapter 3 from the literature on reliance on automation, in which people who intend to rely on a system may nonetheless withdraw from it when they doubt its outputs {{lee2004}}, and from evidence that confidence in algorithmic outputs is lost quickly after errors are observed {{dietvorst2015}}.

The latent interaction between trust and intention was significant and positive (β = .19, SE = .065, p = .004), supporting H6. Adding the interaction raised the explained variance in use from .28 to .31. To interpret the interaction, I computed simple slopes of use on intention at three levels of trust: one standard deviation below the mean, at the mean, and one standard deviation above the mean (Table 5.7).

**Table 5.7** Simple slopes of use on behavioral intention at levels of trust

| Level of trust | Trust (observed scale equivalent) | β (BI → Use) | SE | p |
|---|---|---|---|---|
| Low (−1 SD) | 2.08 | .15 | .08 | .07 |
| Mean | 2.94 | .33 | .06 | < .001 |
| High (+1 SD) | 3.80 | .49 | .07 | < .001 |

*Note.* Observed scale equivalents are the scale mean ± 1 SD (0.86) and are given for orientation only; slopes were computed on the latent metric.

> **Figure 5.2** Simple slopes of self-reported use on behavioral intention at low (−1 SD), mean and high (+1 SD) trust. The x-axis shows intention from −2 SD to +2 SD; the y-axis shows predicted use (standardized). The high-trust line rises steeply; the mean-trust line rises moderately; the low-trust line is nearly flat. The three lines lie close together at the left of the plot, so that at low intention predicted use is similar regardless of trust, and they fan apart as intention increases.

The pattern is clear. Among teachers with high trust in the dashboard's data, intention was a strong predictor of use (β = .49). Among teachers with low trust, the relationship was weak and did not reach conventional significance (β = .15, p = .07). Put concretely, a high-trust teacher who intended to use the dashboard was likely to do so; a low-trust teacher who held the same intention was much less likely to. Because the lines converge at low levels of intention, the practical consequence of low trust lies almost entirely among teachers who *wanted* to use the dashboard. Low trust did not much affect teachers who had no intention of using the tool, since they used it little in any case. It mattered for those who intended to use it and did not follow through.

A Johnson–Neyman analysis located the point at which the slope of use on intention became significant at p < .05: trust values more than 0.78 standard deviations below the mean, corresponding approximately to an observed trust score of 2.27, fell in the region of non-significance. About 21% of the sample had trust scores in this region. For roughly one teacher in five, then, the survey could detect no reliable relationship between how strongly they intended to use the dashboard and how often they reported using it.

I want to be careful about what this result does and does not show. It shows that, in this sample, intention and use were more tightly coupled among teachers who trusted the data. It does not show the mechanism. Several mechanisms are compatible with the pattern. Low-trust teachers might visit the dashboard, find the data at odds with what they knew, and leave without acting, which would reduce their reported frequency of the action-oriented behaviors. They might use other sources of information instead, such as their own gradebooks. Or their intention itself might be of a different character, more a sense of obligation than a plan. The survey cannot distinguish among these, and they have different implications. The question of what low trust does to an intention to use the dashboard was therefore one of the central questions carried into Phase 2.

## 5.8 Robustness checks

### 5.8.1 Alternative model specifications

I estimated two alternative models. The first added direct paths from performance expectancy and effort expectancy to use, which UTAUT does not specify {{venkatesh2003}}. Neither path was significant (PE → Use β = .06, p = .38; EE → Use β = −.03, p = .62), and fit did not improve significantly (scaled Δχ²(2) = 1.12, p = .57). The second alternative specified trust as a predictor of performance expectancy rather than as a correlate, on the reasoning that teachers may judge a tool's usefulness partly on whether they trust its data. This model fitted equivalently, as is expected of a model that turns an association into a directed path, and gave a trust → PE coefficient of .45. It cannot be distinguished from the hypothesized model with cross-sectional data {{kline2016}}, and I retain the hypothesized model on theoretical grounds while noting in Chapter 8 that the causal ordering among beliefs is a limitation of the design.

### 5.8.2 Logged logins as the outcome

For the 288 teachers with linked platform logs, I re-estimated the second-stage model with ln(logins + 1) as an observed outcome in place of the latent self-reported use factor. The results are summarized in Table 5.8 alongside the main model.

**Table 5.8** Comparison of the main model with the log-based model

| Path | Main model (self-reported use, n = 412) | Log-based model (ln logins, n = 288) |
|---|---|---|
| BI → outcome | .33*** | .22*** |
| FC → outcome | .27*** | .18** |
| Trust → outcome | .07 | .05 |
| Trust × BI → outcome | .19** | .14* |
| R² outcome | .31 | .15 |

*Note.* Standardized coefficients. Paths to BI were essentially unchanged in the subsample (PE .40, EE .11, Trust .30). * p < .05, ** p < .01, *** p < .001.

Every relationship held its direction and significance, including the interaction (β = .14, p = .04), but each was smaller, and the model explained only 15% of the variance in logins. Two explanations are plausible. One is that some of the strength of the main model reflected shared method variance between the survey's predictors and its use items, which argues for some caution about the size of the self-report coefficients. The other is that logins are a poor measure of use: they count entries to the system but not what was done there, and they miss any use that occurs outside it. These explanations are not mutually exclusive.

### 5.8.3 Discrepancies between self-reported and logged use

To examine the second explanation, I regressed self-reported use on logged logins and inspected the residuals. Sixty-one teachers (21.2% of the log subsample) reported use more than one standard deviation above what their logins would predict. In other words, they described doing more with the dashboard than their login records suggested. A smaller group of 19 teachers (6.6%) showed the opposite pattern, with logins well above what their reported use would predict. The over-reporting group was not simply a group of enthusiasts inflating their answers: their mean intention (3.71) was only slightly above the sample mean, and their trust (2.91) was at the sample mean. In the optional open-response field at the end of the survey, several of these teachers mentioned downloading reports or printing student lists. This suggested, though the survey could not confirm it, that some use of the dashboard's data took place after the data had left the dashboard, where logins could not record it. The under-reporting group, conversely, may have included teachers who logged in because they were required to, for example during a meeting, without regarding what they did there as "using" the tool.

### 5.8.4 Cluster-robust standard errors

Re-estimating the models with standard errors adjusted for the clustering of teachers in 31 schools left all point estimates unchanged and increased standard errors modestly (by between 4% and 18%). All paths retained their significance except the effect of effort expectancy on intention, whose p-value rose from .029 to .06. The interaction remained significant (p = .009). This reinforces the conclusion that H2 received only weak support, and it indicates that the remaining findings were not artifacts of treating clustered observations as independent.

## 5.9 Latent profile analysis

### 5.9.1 Rationale and model selection

The structural model describes average relationships across the sample. It does not reveal whether teachers fall into groups with qualitatively different configurations of beliefs and behavior. The moderation result suggested that they might: if intention converts into use mainly for teachers who trust the data, there may be a sizable group of teachers who intend to use the dashboard but do not. To examine this directly, and to provide a principled sampling frame for Phase 2 {{ivankova2006}}, I conducted a latent profile analysis on the standardized scale scores for the six constructs.

Models with one to five profiles were estimated, with means free to vary across profiles and variances constrained equal across profiles. Each model was estimated with 500 random sets of starting values and 50 final-stage optimizations, and the best log-likelihood was replicated in every case. Model selection was based on the Bayesian information criterion (BIC), the sample-size-adjusted BIC, the Lo–Mendell–Rubin adjusted likelihood ratio test (LMR), the bootstrap likelihood ratio test (BLRT), entropy, the size of the smallest profile and the substantive interpretability of the solutions. Table 5.9 reports the fit statistics for the two- to five-profile solutions.

**Table 5.9** Latent profile analysis: fit statistics for two to five profiles (n = 412)

| Profiles | LL | Parameters | AIC | BIC | SABIC | Entropy | LMR p | BLRT p | Smallest profile |
|---|---|---|---|---|---|---|---|---|---|
| 2 | −3,021.4 | 19 | 6,080.8 | 6,157.2 | 6,096.9 | .79 | < .001 | < .001 | 38% |
| 3 | −2,912.6 | 26 | 5,877.2 | **5,981.7** | 5,899.2 | **.81** | .002 | < .001 | 27% |
| 4 | −2,893.8 | 33 | 5,853.6 | 5,986.3 | 5,881.6 | .78 | .21 | .01 | 6% |
| 5 | −2,880.2 | 40 | 5,840.4 | 6,001.2 | 5,874.3 | .80 | .38 | .06 | 4% |

*Note.* LL = log-likelihood; AIC = Akaike information criterion; BIC = Bayesian information criterion; SABIC = sample-size-adjusted BIC; LMR = Lo–Mendell–Rubin adjusted likelihood ratio test (k vs. k − 1 profiles); BLRT = bootstrap likelihood ratio test. Bold marks the lowest BIC and the highest entropy.

The three-profile solution was selected. It had the lowest BIC of the solutions examined and the highest entropy (.81), indicating that teachers were classified with reasonable certainty. The LMR test indicated that three profiles fitted significantly better than two (p = .002), but that four did not fit significantly better than three (p = .21). The AIC and the sample-size-adjusted BIC continued to decline slightly beyond three profiles, as these indices often do in samples of this size, and the BLRT favored four profiles over three. The four-profile solution, however, did so by splitting off a small group of 25 teachers (6%) with very high scores on every construct from what became the Engaged users profile; it added no new configuration of beliefs and use. Given the BIC, the LMR test and the principle of parsimony, the three-profile solution was retained. The four- and five-profile solutions are summarized in Appendix C (Table C.2). Average posterior probabilities of profile membership were .91 for the Engaged users, .88 for the Willing but stalled teachers and .89 for the Sceptics, all above the .80 level usually regarded as indicating clear assignment.

### 5.9.2 The three profiles

Table 5.10 reports the mean of each construct in each profile on the original scale metrics, together with logins and background characteristics. Figure 5.3 shows the standardized profile means.

**Table 5.10** Profile means on study variables and characteristics of profile members

| Variable | Engaged users (n = 111, 27%) | Willing but stalled (n = 169, 41%) | Sceptics (n = 132, 32%) | Sample |
|---|---|---|---|---|
| PE | 4.28 | 3.84 | 3.08 | 3.71 |
| EE | 3.87 | 3.40 | 3.07 | 3.42 |
| FC | 3.61 | 2.81 | 2.89 | 3.05 |
| Trust | 3.71 | 2.61 | 2.72 | 2.94 |
| BI | 4.37 | 3.89 | 2.52 | 3.58 |
| Use (0–5) | 3.62 | 1.74 | 1.62 | 2.21 |
| Logins per month (log subsample) | 9.8 (n = 82) | 3.9 (n = 117) | 3.6 (n = 89) | 5.5 (n = 288) |
| Mean years of experience | 10.6 | 11.7 | 14.8 | 12.4 |
| Female (%) | 63 | 64 | 55 | 61 |

*Note.* Profile sizes are based on most likely membership. Differences across profiles were tested with the BCH method; all six construct means differed significantly across profiles (all p < .001). Pairwise contrasts are reported in the text.

> **Figure 5.3** Standardized means of the six constructs by latent profile. Engaged users (27%) lie above the sample mean on every construct, highest on intention and use. Willing but stalled teachers (41%) lie slightly above the mean on performance expectancy and intention but below it on facilitating conditions, trust and, most of all, use; their line drops sharply from intention to use. Sceptics (32%) lie below the mean on every construct, lowest on performance expectancy and intention. The Stalled and Sceptic lines nearly coincide at the use end of the plot.

The first profile, labeled **Engaged users** (27% of the sample), scored above the sample mean on every construct. These teachers regarded the dashboard as useful and easy to use, reported the best facilitating conditions, trusted its data more than any other group (3.71) and reported by far the most use (3.62 on the 0–5 scale). Their logins confirmed the pattern: they logged in on average 9.8 times per month, more than twice as often as either other group.

The second profile, labeled **Willing but stalled** (41%), is the largest and the most important for the argument of this study. These teachers held positive beliefs about the dashboard's usefulness (PE 3.84, above the sample mean) and clearly intended to use it (BI 3.89, also above the sample mean and far above the Sceptics, p < .001). Yet their reported use (1.74) was far below that of Engaged users and statistically indistinguishable from that of the Sceptics (difference 0.12, p = .31). Their logins (3.9 per month) told the same story. Two features set them apart. They had the lowest trust in the dashboard's data of any group (2.61, significantly below Engaged users, p < .001, and slightly but not significantly below Sceptics, p = .27), and they reported the weakest facilitating conditions (2.81). Their defining characteristic was a gap: they wanted to use the dashboard and largely did not.

The third profile, labeled **Sceptics** (32%), scored below the sample mean on every construct and was distinguished above all by low performance expectancy (3.08) and low intention (2.52). These teachers were not persuaded that the dashboard would help them, and they did not intend to use it much. Their low use was therefore, in acceptance-model terms, the expected consequence of their low intention. They were also the most experienced group (14.8 years on average, significantly more than the other two profiles, p = .002), which is consistent with the view that experienced teachers rely more on established ways of knowing their students, though the survey could not establish why.

A comparison between the Stalled and Sceptic profiles brings the central puzzle into focus. The two groups reported almost identical levels of use, and almost identical facilitating conditions as the FC scale measured them (2.81 and 2.89). What separated them was intention. If the acceptance models were a sufficient account, the Stalled teachers' higher intention should have produced more use. It did not, and the facilitating conditions scale offers no explanation, because on that scale the Stalled teachers were no worse off than the Sceptics. Low trust provides part of the answer, in line with the moderation result in Section 5.7, but trust and FC together left much of the gap unaccounted for.

## 5.10 Summary of hypothesis tests and answer to RQ1

Table 5.11 summarizes the outcomes of the six hypothesis tests.

**Table 5.11** Summary of hypothesis tests

| Hypothesis | Path | β | Result |
|---|---|---|---|
| H1 | PE → BI (+) | .41*** | Supported |
| H2 | EE → BI (+) | .12* | Supported (weakly; p = .06 with cluster-robust SEs) |
| H3 | Trust → BI (+) | .29*** | Supported |
| H4 | BI → Use (+) | .33*** | Supported |
| H5 | FC → Use (+) | .27*** | Supported |
| H6 | Trust moderates BI → Use (stronger at high trust) | .19** | Supported (simple slopes .49 high, .15 low) |

*Note.* * p < .05, ** p < .01, *** p < .001.

The answer to RQ1 is as follows. In this sample of 412 secondary teachers, performance expectancy and trust in dashboard data were substantial predictors of the intention to use the district dashboard, and effort expectancy a weak one; together they explained 48% of the variance in intention. Intention and facilitating conditions predicted self-reported use and, with the interaction, explained 31% of its variance. Trust in the data shaped use in two ways: indirectly, by strengthening intention, and conditionally, by determining how far intention was carried through into use. The relationships held, at reduced strength, when logged logins replaced self-reported use. The trust-extended model therefore identified trust as a construct that acceptance models drawn from general information systems research {{davis1989}} {{venkatesh2003}} do not include but that matters for teachers' use of data tools.

The answer is also limited, in ways that motivated Phase 2. The model explained far less of use than of intention, and 41% of teachers fell into a profile defined by intention that did not become use.

## 5.11 Puzzles carried forward to Phase 2

In an explanatory sequential design, the quantitative results determine what the qualitative phase must explain, and the connection between phases should be made explicit {{ivankova2006}} {{creswell2018}}. Five puzzles emerged from the analyses in this chapter. They shaped the selection of interview participants and the protocol in Appendix B, and each is returned to in Chapters 6 and 7.

The first puzzle concerns **what trust in dashboard data consists of**. Trust was the lowest-scoring construct, the second strongest predictor of intention and the moderator of the intention–use link. The five trust items treated it as a single belief about the accuracy and dependability of the data, and they performed well psychometrically. But the higher missingness on those items, and the school-level clustering of trust scores, suggested that teachers' trust might rest on considerations that the items did not separate, including how and when the data were produced and what they were used for. Phase 2 asked teachers what made them trust or distrust the dashboard's data, and what, if anything, had changed their trust.

The second puzzle concerns **the Willing but stalled teachers**. Why did 41% of teachers who intended to use the dashboard, and who believed it would be useful, use it no more than teachers who did not intend to? Their low trust is part of the answer, but their facilitating conditions as measured were no worse than those of the Sceptics. Phase 2 therefore asked these teachers to describe, as concretely as possible, the last time they had meant to use the dashboard and had not.

The third puzzle concerns **what facilitating conditions measured**. FC predicted use about as strongly as intention did, and correlated with logins about as strongly as intention did. Yet the scale's items concerned resources, knowledge, compatibility and help, and did not distinguish the Stalled teachers from the Sceptics. This raised the possibility that FC was standing in for some other condition associated with school-level organization. The interviews asked teachers what made it easier or harder to use the dashboard in an ordinary week.

The fourth puzzle concerns **what counts as use**. Self-reported use and logged logins correlated at .58, and one in five teachers in the log subsample described more use than their logins recorded, with survey comments hinting at exporting and printing. Phase 2 asked teachers to walk through what they did with dashboard data, including what they did after leaving the dashboard.

The fifth, smaller puzzle concerns **the weakness of effort expectancy and the doubts of the Sceptics**. Effort expectancy barely predicted intention, and the Sceptics, the most experienced group, doubted the dashboard's usefulness more than its ease of use. The interviews asked Sceptics what the dashboard would have to offer to be worth their time, and asked all teachers what was hard about using it.

These puzzles also determined the connecting point. Because the Willing but stalled profile embodied the central gap between intention and use, it was deliberately over-represented in the Phase 2 sample, with Engaged users and Sceptics included as contrasting cases. The selection procedure, which drew on most likely profile membership and posterior probabilities from the three-profile solution, is described at the start of Chapter 6.

## 5.12 Chapter summary

This chapter tested a trust-extended acceptance model on survey data from 412 secondary teachers, with missing data (3.1% of item responses) handled by FIML under MLR estimation. The measurement model fitted well, and the six constructs showed adequate reliability, convergent validity and discriminant validity, with common method variance unlikely to account for the results. All six hypotheses were supported, H2 only weakly. Performance expectancy (β = .41) and trust (β = .29) were the main predictors of intention; intention (β = .33) and facilitating conditions (β = .27) predicted use; and trust moderated the intention–use relationship (β = .19), so that intention predicted use strongly among high-trust teachers (β = .49) and only weakly among low-trust teachers (β = .15, p = .07). A latent profile analysis identified three groups: Engaged users (27%), Willing but stalled teachers (41%) and Sceptics (32%). The results established that trust matters and that intention often fails to become use, but they could not establish why. Chapter 6 turns to the 24 teachers interviewed in Phase 2 to explain these relationships in teachers' own terms.
