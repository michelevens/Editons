# Appendix C: Proofs of Propositions

This appendix proves the three propositions stated in Chapter 3. To keep the proofs self-contained, Section C.1 restates the elements of the model on which they rely, in the notation of Chapter 3. Section C.2 derives the equilibrium of a single labor market $c$ and its response to an increase in the stock of resident remote workers $R_c$. Sections C.3 to C.5 restate and prove Propositions 3.1 to 3.3. Section C.6 records two remarks on how the results extend when assumptions are relaxed. Throughout, the analysis is local: I consider a small change in $R_c$ around an initial equilibrium and work with log-linear responses.

## C.1 Assumptions

Market $c$ is small relative to the national economy, so the national reservation utility $\bar{u}$ and the remote wage $w^R$ are taken as given. Residents are of three types: local tradable workers, who number $N^T_c$ and earn $w^T_c$; non-tradable workers, who number $N^{NT}_c$ and earn $w^{NT}_c$; and remote workers, who number $R_c$ and earn the nationally set wage $w^R$. Every resident consumes housing at rent $r_c$ and a composite of tradable goods and local non-tradable services. Preferences are Cobb–Douglas with housing share $\alpha \in (0,1)$, so the indirect utility of a resident with income $w$ in market $c$ is, up to a constant,

$$
U = \ln w - \alpha \ln r_c + A_c, \tag{?}
$$

where $A_c$ is the amenity value of the market. I maintain five assumptions.

**(A1) Mobility of non-tradable workers.** Non-tradable workers are freely mobile across markets, so in equilibrium their utility in $c$ equals $\bar{u}$.

**(A2) Non-tradable demand.** Residents spend a share $s \in (0,1)$ of income on local non-tradable services, with $\alpha + s < 1$. Services are produced with labor alone under constant returns, with one unit of labor producing one unit of service, so the service price equals $w^{NT}_c$.

**(A3) Predetermined tradable sector.** Over the horizon studied, tradable employment $N^T_c$ and the tradable wage $w^T_c$ are predetermined: tradable output is sold on national markets and is not affected to first order by local demand.

**(A4) Housing supply.** Housing is supplied by local owners according to $H^S_c = \bar{H}_c\, r_c^{\eta_c}$ with $\eta_c \ge 0$. Housing demand is $\alpha Y_c / r_c$, where $Y_c$ is total resident income.

**(A5) Remote arrivals as the shock.** Changes in $R_c$ are treated as an exogenous shift. Remote workers' location choices determine $R_c$ in the full model of Chapter 3; the comparative statics below take the resulting change in $R_c$ as given.

Two features of these assumptions deserve comment. First, (A2) applies the service share to total resident income, including the income of non-tradable workers themselves, which generates the familiar Keynesian base multiplier. Second, (A1) uses the indirect utility in Equation (C.1) without a term in the price of services. Section C.6 shows that adding such a term rescales $\alpha$ but changes none of the signs proved here.

## C.2 Equilibrium and comparative statics

From (A1) and Equation (C.1), the non-tradable wage must satisfy

$$
\ln w^{NT}_c = \bar{u} - A_c + \alpha \ln r_c. \tag{?}
$$

Market clearing for non-tradable services under (A2) requires that the wage bill of the sector equal local spending on its output:

$$
w^{NT}_c N^{NT}_c = s\, Y_c, \qquad Y_c = w^T_c N^T_c + w^R R_c + w^{NT}_c N^{NT}_c. \tag{?}
$$

Define the export base $B_c \equiv w^T_c N^T_c + w^R R_c$, the income that residents earn outside the local service sector. Substituting the first part of Equation (C.3) into the second gives $Y_c = B_c + s Y_c$, so

$$
Y_c = \frac{B_c}{1-s}, \qquad N^{NT}_c = \frac{s}{1-s}\,\frac{B_c}{w^{NT}_c}. \tag{?}
$$

Housing market clearing under (A4) gives $\alpha Y_c / r_c = \bar{H}_c r_c^{\eta_c}$, so that

$$
\ln r_c = \frac{1}{1+\eta_c}\left[\ln \alpha + \ln Y_c - \ln \bar{H}_c\right]. \tag{?}
$$

Let $\theta_c \equiv w^R R_c / B_c \in (0,1)$ denote the share of remote income in the export base. Under (A3), $d \ln B_c / d \ln R_c = \theta_c$, and by Equation (C.4), $d \ln Y_c / d \ln R_c = \theta_c$ as well. Differentiating Equation (C.5), Equation (C.2) and the second part of Equation (C.4) in turn yields the three responses on which the propositions rest:

$$
\frac{d \ln r_c}{d \ln R_c} = \frac{\theta_c}{1+\eta_c}, \tag{?}
$$

$$
\frac{d \ln w^{NT}_c}{d \ln R_c} = \frac{\alpha\,\theta_c}{1+\eta_c}, \tag{?}
$$

$$
\frac{d \ln N^{NT}_c}{d \ln R_c} = \theta_c - \frac{\alpha\,\theta_c}{1+\eta_c} = \theta_c\left(1 - \frac{\alpha}{1+\eta_c}\right). \tag{?}
$$

It is convenient to write the results in terms of the inverse supply elasticity $\kappa_c \equiv 1/\eta_c \in [0,\infty]$, with $\kappa_c = 0$ for perfectly elastic and $\kappa_c = \infty$ for perfectly inelastic supply. Since $1/(1+\eta_c) = \kappa_c/(1+\kappa_c)$,

$$
\frac{d \ln r_c}{d \ln R_c} = \theta_c\,\frac{\kappa_c}{1+\kappa_c}, \qquad \frac{d \ln N^{NT}_c}{d \ln R_c} = \theta_c\left(1 - \frac{\alpha\,\kappa_c}{1+\kappa_c}\right). \tag{?}
$$

## C.3 Proposition 3.1

**Proposition 3.1.** Under (A1)–(A5), the elasticity of non-tradable employment with respect to the remote-worker stock, $d \ln N^{NT}_c / d \ln R_c$, is strictly positive. It is strictly decreasing in the inverse housing supply elasticity $1/\eta_c$, and it lies between $\theta_c(1-\alpha)$, attained when housing supply is perfectly inelastic, and $\theta_c$, attained when it is perfectly elastic. The implied local multiplier, the number of non-tradable jobs per additional remote worker, is

$$
\frac{d N^{NT}_c}{d R_c} = \frac{s}{1-s}\,\frac{w^R}{w^{NT}_c}\left(1 - \frac{\alpha}{1+\eta_c}\right). \tag{?}
$$

*Proof.* Positivity follows from Equation (C.8). Since $\eta_c \ge 0$, the term $\alpha/(1+\eta_c)$ is at most $\alpha$, and $\alpha < 1$ by assumption, so $1 - \alpha/(1+\eta_c) \ge 1 - \alpha > 0$; with $\theta_c > 0$ the elasticity is strictly positive.

For monotonicity, use the second expression in Equation (C.9). Let $g(\kappa) \equiv \kappa/(1+\kappa)$. Then $g'(\kappa) = 1/(1+\kappa)^2 > 0$, so

$$
\frac{\partial}{\partial \kappa_c}\left[\frac{d \ln N^{NT}_c}{d \ln R_c}\right] = -\frac{\alpha\,\theta_c}{(1+\kappa_c)^2} < 0, \tag{?}
$$

and the elasticity is strictly decreasing in $\kappa_c = 1/\eta_c$, holding $\theta_c$ fixed. The bounds follow from $g(0) = 0$ and $g(\kappa) \to 1$ as $\kappa \to \infty$.

For the multiplier, note that $d N^{NT}_c / d R_c = (N^{NT}_c / R_c)\, d \ln N^{NT}_c / d \ln R_c$. From Equation (C.4), $N^{NT}_c = s B_c / [(1-s) w^{NT}_c]$, and $\theta_c / R_c = w^R / B_c$. Multiplying gives Equation (C.10). $\blacksquare$

The intuition is that remote income raises local service demand one-for-one in proportion to its share of the export base, but part of the demand increase is absorbed by a higher service wage, which mobile workers require in compensation for higher rents. The less elastic is housing, the more rents rise, the more of the demand shock is absorbed by wages rather than employment, and the smaller is the employment response.

## C.4 Proposition 3.2

**Proposition 3.2.** Under (A1)–(A5), the elasticities of the rent and of the non-tradable wage with respect to the remote-worker stock are strictly positive whenever housing supply is less than perfectly elastic, and both are strictly increasing in $1/\eta_c$. The ratio of the wage response to the rent response equals $\alpha$ and does not depend on $\eta_c$.

*Proof.* From Equation (C.9), $d \ln r_c / d \ln R_c = \theta_c\, g(\kappa_c)$, which is strictly positive for $\kappa_c > 0$ and has derivative $\theta_c/(1+\kappa_c)^2 > 0$ in $\kappa_c$. Equation (C.7) shows that the wage response is $\alpha$ times the rent response, so it inherits both properties, and the ratio of the two is $\alpha$ for every $\eta_c$. $\blacksquare$

Proposition 3.1 and Proposition 3.2 together imply that heterogeneity in housing supply elasticity moves the employment and rent responses in opposite directions. This is the prediction examined with the split by housing-supply elasticity in Chapter 6. The second part of Proposition 3.2 is what links the wage and rent estimates to the incidence calculation, because it pins down how much of the rent increase a mobile worker must be compensated for.

If house prices equal the present value of rents at a constant user cost, the elasticity of house prices with respect to $R_c$ equals that of rents. A larger price than rent response, as in the estimates of Chapter 6, would be consistent with expectations of further rent growth being capitalized into prices, which the static model does not capture.

## C.5 Proposition 3.3

**Proposition 3.3.** Consider a small increase in $R_c$ under (A1)–(A5), and hold incumbents' housing consumption at its initial level. Then, to first order:

1. Mobile non-tradable workers, including new entrants to the sector, are no better or worse off; their utility remains $\bar{u}$.
2. An incumbent renter household that spends a share $\sigma_i$ of its income on housing loses on net if and only if $\sigma_i$ exceeds the ratio of the response of its wage to the response of the rent, $d \ln w_i / d \ln r_c$. For a household earning the non-tradable wage this ratio equals $\alpha$; for a household in the tradable sector it is zero, so such a household loses for any $\sigma_i > 0$.
3. Incumbent owners of the initial housing stock capture the rent increase that renters pay on that stock: their first-order gain equals the renters' first-order increase in rent payments, so that the transfer nets to zero, and the sum of first-order gains across incumbent residents and owners is strictly positive.

*Proof.* Part 1 is immediate from (A1): Equation (C.2) holds before and after the shock.

For Part 2, the first-order change in the money-metric welfare of household $i$, expressed as a share of its income, is the change in its log income less its rent share times the change in log rent:

$$
\frac{dV_i}{y_i} = d \ln w_i - \sigma_i\, d \ln r_c = \left(\frac{d \ln w_i}{d \ln r_c} - \sigma_i\right) d \ln r_c. \tag{?}
$$

Since $d \ln r_c > 0$ by Proposition 3.2, the household loses if and only if $\sigma_i > d \ln w_i / d \ln r_c$, which is the general statement of Part 2. For a non-tradable household, Equation (C.7) and Equation (C.6) give $d \ln w^{NT}_c = \alpha\, d \ln r_c$, so

$$
\frac{dV_i}{y_i} = (\alpha - \sigma_i)\, d \ln r_c, \tag{?}
$$

which is negative if and only if $\sigma_i > \alpha$, because $d \ln r_c > 0$ by Proposition 3.2. A household with $\sigma_i = \alpha$ is exactly compensated, which is the indifference condition of Part 1. For a tradable household, $d \ln w^T_c = 0$ by (A3), so $dV_i / y_i = -\sigma_i\, d \ln r_c < 0$.

For Part 3, let $H_0$ be the initial stock. With an upward-sloping supply curve, the first-order change in owners' surplus is $H_0\, dr_c = r_c H_0\, d \ln r_c$; new units are supplied at a marginal cost equal to the rent, so they add nothing at first order. Holding incumbents' housing consumption at $H_0$, their combined first-order loss from higher rents is the same amount, $r_c H_0\, d \ln r_c$, so the two cancel. What remains is the wage gain of incumbent non-tradable workers,

$$
\sum_{i \in NT_0} dV_i = w^{NT}_c N^{NT}_{c,0}\, d \ln w^{NT}_c = \frac{\alpha\,\theta_c}{1+\eta_c}\, w^{NT}_c N^{NT}_{c,0}\, d \ln R_c > 0, \tag{?}
$$

where $N^{NT}_{c,0}$ is initial non-tradable employment. $\blacksquare$

Proposition 3.3 gives the incidence calculation in Chapter 6 its form. The empirical counterpart of the threshold $\alpha$ is the ratio of the estimated wage and rent effects, $1.2/4.6 \approx 0.26$, so that a renter in non-tradables loses when rent exceeds about 26 percent of income. Part 3 shows that the average gain is positive, but also that it is composed of a transfer from renters to owners plus a wage gain. Whether the overall pattern is regressive depends on how rent shares and homeownership vary with income; where lower-paid workers are more likely to rent, as {{mongey2021|n}} document for workers in jobs that cannot be done from home, the losses fall disproportionately on them.

## C.6 Remarks on the assumptions

**Price of non-tradable services.** If the indirect utility includes the service price, $U = \ln w - \alpha \ln r_c - s \ln w^{NT}_c + A_c$, then (A1) implies $(1-s)\ln w^{NT}_c = \bar{u} - A_c + \alpha \ln r_c$. Every step above goes through with $\alpha$ replaced by $\tilde{\alpha} \equiv \alpha/(1-s)$. The employment elasticity becomes $\theta_c[1 - \tilde{\alpha}/(1+\eta_c)]$, which remains strictly positive because $\alpha + s < 1$ implies $\tilde{\alpha} < 1$. All monotonicity results, and the form of the threshold in Proposition 3.3, are unchanged, with the threshold now equal to $\tilde{\alpha}$.

**Tradable responses.** Relaxing (A3) to allow tradable workers to leave in response to higher rents would reduce $B_c$ by an amount proportional to the rent increase, and therefore by more where housing is inelastic. This would strengthen the monotonicity in Proposition 3.1, while the sign of the employment effect would remain positive provided the induced tradable outflow is small relative to $\theta_c$. The near-zero tradable employment effect reported in Chapter 6 suggests that (A3) is a reasonable approximation over the horizon studied.
