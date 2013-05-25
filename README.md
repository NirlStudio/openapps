<h1>OpenApps PHP Modules.</h1>
Openapps contains some reusable and lightweight components, which is designed for rapid development of LAMP-based web applictions.

<h2>/modules/common</h2>
Some primary feature components.
<ul>
  <li> common.php - providing several common functions for all other components.</li>
  <li> NirlLog.php - saving log data into error_log and/or mysql db.</li>
  <li> NrlrlSession - providing some enhanced features adding to basic php session.</li>
  <li> NirlSQLi - providing a more friendly programming interface basing on mysqli of php.</li>
  <li> NirlMemcached - generating a memcached instance by name according to configuration.</li>
  <li> NirlShield - providing a simple protection mechanism to the server.</li>
  <li> NirlAuth - implementing a Digest authentication service.</li>
  <li> NirlService - providing a small framework to easily develop a http service component.</li>
</ul>

<h2>/modules/minifx</h2>
A simple web MVC framework.
<ul>
  <li>minifx.js - providing a simple view check/update mechanism, which support both full and incremental update.</li>
  <li>minifx.php - the base class to implement backend services to provide data to view.</li>
</ul>
