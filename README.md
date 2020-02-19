The problem: Per the Scientific Method, PhpUnit tests need to be in control of the source data feeding the behavior being tested. And Model Factories, while handy, don't supply realistic relationships between records. You can make them, of course, by having them reference eachother in the foreign key fields. But then you risk infinite recursion if your database isn't normalized properly. And that still doesn't address the problem that one table doesn't necessarily represent one idea, necessitating multiple Model Factories for the same Model, and that starts to get a little messy.

i.e. a 'user' table might be a student, or it might be an admin. If it's a student, that means the user record must have particular values therein, and must have a particular relationship with a 'class' record, the 'class' record must have a corresponding 'school', and so-on.
What if you could just run something like `php artisan concept:generate studen`, and have all dependent-records of dependant-records generate for you on a recursive fashion?

Taking the example a bit further, if you have a lecture 'attendee' record, that means a 'student' with a related 'school' record. And there's a many-to-many relationship with a 'lecture' record... which _also_ links to a 'school' record. So what if our dummy-data generator were smart enough to leverage the _same_ school record for both?

That is the purpose of this package. To generate _mostly_ random, _mostly_ lorem ipsum dummy-data with **cascading realistic relationships** for the purposes of automated tests or local QA.

***

Similar to how Model classes encompass a database table, a Concept class encompasses a conceptual entity. They can be just as lightweight, and are structured in a similar manor, starting with a base Model name, a list of relationships that needed to be loaded with it, and the option to override the Model's relationship method with one of it's own, that could serve back a nested Concept object. It's the ability to replace a relationship with another Concept that allows this tool to become exponentially smarter the more it's leveraged.
