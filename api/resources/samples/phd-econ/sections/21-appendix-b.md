# Appendix B: Additional Robustness Results

This appendix reports robustness checks that support the main estimates in Chapters 5 and 6 but are too detailed for the body of the dissertation. Section B.1 varies the definition of treatment. Section B.2 varies the comparison group and the conditioning set. Section B.3 varies the approach to inference. Section B.4 reports a placebo exercise in which treatment dates are reassigned at random. Unless stated otherwise, every estimate uses the Callaway–Sant'Anna estimator {{callaway2021}} with not-yet-treated and never-treated markets as controls, aggregated to an overall average treatment effect on the treated with weights proportional to cohort size, and every estimate is reported in percent, with standard errors clustered by market in parentheses. The baseline estimates against which the alternatives should be compared are an effect of 2.1 percent (0.6) on non-tradable employment and 4.6 percent (1.1) on median asking rents.

## B.1 Alternative treatment thresholds

The treatment definition in Chapter 4 and Appendix A has two parameters: the threshold for the scaled net inflow of remote-capable workers, set at 0.5 percent of base employment, and the persistence requirement, set at two consecutive quarters. Both are judgment calls. A lower threshold or a shorter persistence requirement treats more markets, earlier, and on the basis of smaller inflows; a higher threshold or a longer requirement treats fewer markets, later, and on the basis of larger inflows. Because the outcome effects should scale with the size of the inflow, the model of Chapter 3 predicts that estimated effects should rise with the threshold, and a robust result is one in which they rise smoothly rather than changing sign or jumping.

Table B.1 reports the results. Each row recodes treatment dates from the same flows, re-forms cohorts, and re-estimates. The number of observations is unchanged because the panel is balanced and every market remains in the sample either as treated or as a control.

**Table B.1** Alternative treatment thresholds

| Threshold (share of base employment) | Consecutive quarters | Treated markets | Non-tradable employment | Median asking rent |
|---|---|---|---|---|
| 0.25% | 2 | 371 | 1.7 (0.5) | 3.8 (1.0) |
| 0.50% (baseline) | 2 | 306 | 2.1 (0.6) | 4.6 (1.1) |
| 0.75% | 2 | 251 | 2.4 (0.7) | 5.3 (1.3) |
| 1.00% | 2 | 198 | 2.7 (0.8) | 6.0 (1.5) |
| 0.50% | 1 | 338 | 1.8 (0.6) | 4.1 (1.1) |
| 0.50% | 3 | 279 | 2.2 (0.6) | 4.8 (1.2) |
| Observations | | 19,200 | 19,200 | 19,200 |
| Markets | | 600 | 600 | 600 |

*Note.* Callaway–Sant'Anna overall ATT in percent, not-yet-treated and never-treated controls. Standard errors clustered by market in parentheses. All estimates in the employment and rent columns are significant at the 5 percent level.

Two patterns stand out. First, both the employment and the rent effects increase monotonically with the threshold, from 1.7 to 2.7 percent for employment and from 3.8 to 6.0 percent for rents, which is what one would expect if the treatment captures a dose of remote-worker arrivals and if larger doses have larger effects. Second, the ratio of the rent effect to the employment effect is stable across rows, between about 2.2 and 2.3, which indicates that the threshold changes the scale of the shock but not the way it is split between quantities and prices. The single-quarter definition yields somewhat smaller effects than the baseline, consistent with the inclusion of markets that cross the threshold briefly and then fall back. The three-quarter definition yields estimates close to the baseline, suggesting that the two-quarter requirement already filters out most transitory crossings.

Because the multiplier in Chapter 5 is the ratio of the employment effect to the inflow, the relevant question for the multiplier is whether the inflow rises with the threshold in proportion to the employment effect. In these specifications it does approximately, and the implied multiplier remains within one standard error of the baseline value of 0.6 in every row. I do not report the multipliers separately because their standard errors are large relative to the differences among them.

## B.2 Alternative control groups and conditioning

The baseline uses both not-yet-treated and never-treated markets as controls. Each choice rests on a different version of the parallel trends assumption. Using only never-treated markets requires that the 294 markets that never receive large inflows provide a valid counterfactual for those that do; this is credible if the determinants of treatment are unrelated to outcome trends, but less so if never-treated markets differ systematically, for instance by being smaller or less amenity-rich. Using only not-yet-treated markets compares markets that are all eventually treated, which may make them more similar, but it shrinks the control group over time and leaves no controls at all for the last cohort's post-treatment period beyond 2022Q1.

Table B.2 reports the main alternatives. The final rows add, respectively, baseline covariates under the doubly robust version of the Callaway–Sant'Anna estimator, and the imputation estimator of {{borusyak2024|n}}, which uses all untreated observations to estimate market and quarter effects.

**Table B.2** Alternative control groups

| Specification | Control group | Non-tradable employment | Median asking rent |
|---|---|---|---|
| Baseline (CS) | Not-yet-treated and never-treated | 2.1 (0.6) | 4.6 (1.1) |
| CS | Never-treated only | 2.0 (0.6) | 4.4 (1.1) |
| CS | Not-yet-treated only | 2.2 (0.7) | 4.9 (1.3) |
| Sun–Abraham | Last-treated cohort (2022Q1), sample through 2021Q4 | 1.9 (0.8) | 4.3 (1.5) |
| CS, doubly robust | Not-yet-treated and never-treated, with baseline covariates | 2.0 (0.6) | 4.5 (1.1) |
| BJS imputation | All untreated observations | 2.2 (0.5) | 4.7 (1.0) |
| Observations | | 19,200 | 19,200 |
| Markets | | 600 | 600 |

*Note.* ATT in percent. Standard errors clustered by market in parentheses. Baseline covariates in the doubly robust specification are log base employment, the 2019 share of employment in teleworkable occupations and the amenity index, all measured before treatment. The Sun–Abraham row uses fewer quarters; its estimation sample is 12,000 market-quarters (600 markets by 20 quarters, 2017Q1–2021Q4). All estimates are significant at the 5 percent level.

The estimates are closely clustered. Using never-treated controls only lowers the employment effect slightly, to 2.0 percent, while using not-yet-treated controls only raises it slightly, to 2.2 percent with a larger standard error, as expected given the shrinking control group. The specification of {{sun2021|n}} that uses the last-treated cohort as the control group gives 1.9 percent with a wider standard error, because it discards the post-2021Q4 period. Conditioning on baseline covariates changes the estimate by one-tenth of a percentage point, which suggests that observable differences in size, occupational mix and amenities are not driving the comparison. The imputation estimator, which is efficient under homogeneous-effect assumptions and remains unbiased when they fail, gives the most precise estimate at 2.2 percent (0.5). None of these alternatives moves the employment estimate outside the baseline's 95 percent confidence interval, and the rent estimates are similarly stable.

## B.3 Clustering and bootstrap inference

The baseline standard errors are clustered by labor market, the level at which treatment is assigned. This choice follows from the design-based argument that clustering should match the level of treatment assignment {{abadie2023}}, and from the evidence that difference-in-differences standard errors that ignore serial correlation within units can badly overstate precision {{bertrand2004}}. Two concerns remain. Markets may be correlated within broader regions, for example through common regional shocks, in which case clustering at a higher level would be appropriate. And for the cohort-specific estimates, the effective number of treated clusters is smaller, which can make conventional cluster-robust inference unreliable {{cameron2015}}.

Table B.3 addresses both. The upper panel reports the overall employment effect with alternative standard errors. The lower panel reports wild cluster bootstrap confidence intervals and p-values for the cohort-specific estimates, which are reported with conventional standard errors in Table 5.3.

**Table B.3** Clustering and bootstrap

| Inference method | Clusters | Estimate | SE or 95% interval | p-value |
|---|---|---|---|---|
| *Overall ATT, non-tradable employment* | | | | |
| Heteroskedasticity-robust, no clustering | 19,200 obs. | 2.1 | 0.3 | <0.001 |
| Clustered by market (baseline) | 600 | 2.1 | 0.6 | <0.001 |
| Two-way clustered, market and quarter | 600 × 32 | 2.1 | 0.7 | 0.003 |
| Clustered by region | 48 | 2.1 | 0.8 | 0.009 |
| Wild cluster bootstrap, by market | 600 | 2.1 | [0.9, 3.3] | 0.001 |
| *Cohort ATT, wild cluster bootstrap by market* | | | | |
| 2020Q3 cohort | 84 treated | 2.6 | [1.1, 4.1] | 0.001 |
| 2021Q1 cohort | 97 treated | 2.2 | [0.9, 3.5] | 0.002 |
| 2021Q3 cohort | 73 treated | 1.8 | [0.3, 3.3] | 0.020 |
| 2022Q1 cohort | 52 treated | 1.2 | [−0.5, 2.9] | 0.160 |
| Observations | | 19,200 | | |
| Markets | | 600 | | |

*Note.* Estimates in percent. Regions are 48 synthetic groupings of contiguous markets. Bootstrap intervals use 9,999 replications with Rademacher weights, imposing the null for p-values. p-values are two-sided.

Ignoring clustering roughly halves the standard error, to 0.3, and would considerably overstate the precision of the estimates, exactly as the literature warns. Clustering at the regional level, with 48 clusters, raises the standard error to 0.8, but the estimate remains significant at the 1 percent level. Two-way clustering by market and quarter gives 0.7. The wild cluster bootstrap interval at the market level, from 0.9 to 3.3 percent, is close to the conventional interval of 2.1 ± 1.96 × 0.6.

For the cohort estimates, the bootstrap confirms that the effects for the three earliest cohorts are statistically distinguishable from zero. The 2022Q1 cohort's effect of 1.2 percent is not, with a bootstrap p-value of 0.16. This cohort is the smallest, with 52 markets, and has the shortest exposure window, so its imprecision is unsurprising. The declining pattern of cohort effects therefore rests mainly on the three earlier cohorts, and the conclusion that later cohorts have smaller effects should be read as suggestive rather than established; Chapter 5 discusses whether the decline reflects shorter exposure or a genuine difference in the effect of arrivals.

## B.4 Placebo assignment of treatment dates

As a final check, I reassign treatment dates at random. In each of 1,000 replications, the 306 treated markets are drawn at random from all 600 markets and assigned the observed cohort dates in the observed proportions, and the overall effect on non-tradable employment is re-estimated. If the main estimate reflected a feature of the estimator or of the panel's structure rather than the treatment, placebo estimates of similar size would be common. The placebo distribution is centered at 0.0 percent with a standard deviation of 0.5, and none of the 1,000 placebo estimates is as large as the actual estimate of 2.1 percent. The same exercise for rents gives a placebo distribution centered at 0.1 percent, with no replication reaching 4.6 percent.

Together with the honest bounds reported in Chapter 5 {{rambachan2023}}, under which the effect at four quarters after treatment remains positive for relative violations of parallel trends up to $\bar{M} = 0.8$, these checks indicate that the main conclusions of the dissertation are not artifacts of the treatment definition, the comparison group or the method of inference, within the limits of the synthetic panel and of the design discussed in Chapter 7.
