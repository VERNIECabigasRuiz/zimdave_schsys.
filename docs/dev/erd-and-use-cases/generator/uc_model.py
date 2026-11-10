"""Data shapes for the use case documents."""
from collections import namedtuple

UC = namedtuple("UC", "id name actors route goal pre flow alt post tech")
View = namedtuple("View", "name actors route shows query")
