DiWrapper
=========

DiWrapper is a Zend Framework 2 module that uses auto-generated factory code for dependency-injection. 
It saves you a lot of work, since there's no need anymore for writing Zend\ServiceManager factory closures 
and keeping them up-to-date manually.

DiWrapper scans your code (using Zend\Di) and creates factory methods automatically. If the factory methods are outdated, DiWrapper
updates them in the background. Therefore, you develop faster and performance in production is great, too!

Features:
- DI definition scanning and factory code generation
- Can deal with shared instances
- Can be used as a fallback abstract factory for Zend\ServiceManager, just like Zend\Di\Di
- Detects outdated generated code and automatic rescanning (great for development)
- Can create new instances or reuse instances created before

Current limitations:
- Only constructor-injection supported (but e.g. not setter-injection)

Installation
============

todo
